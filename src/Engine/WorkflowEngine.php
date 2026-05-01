<?php

declare(strict_types=1);

namespace Laraflow\Engine;

use Closure;
use Laraflow\Contracts\GuardEvaluatorInterface;
use Laraflow\Data\GuardEvent;
use Laraflow\Data\GuardResult;
use Laraflow\Data\Marking;
use Laraflow\Data\MiddlewareContext;
use Laraflow\Data\Transition;
use Laraflow\Data\TransitionBlocker;
use Laraflow\Data\TransitionResult;
use Laraflow\Data\WorkflowDefinition;
use Laraflow\Data\WorkflowEvent;
use Laraflow\Enums\ListenerErrorMode;
use Laraflow\Enums\WorkflowEventType;
use Laraflow\Exceptions\ListenerExceptionAggregate;
use Throwable;

class WorkflowEngine
{
    private Marking $marking;

    /** @var array<string, bool> */
    private array $placeNames;

    /** @var array<string, array<int, array{priority: int, scope: string, cb: callable, seq: int}>> */
    private array $listeners = [];

    private int $listenerSeq = 0;

    /** @var array<callable> */
    private array $middleware;

    private readonly ListenerErrorMode $listenerErrorMode;

    /** @var ?Closure(Throwable, WorkflowEvent): void */
    private readonly ?Closure $onListenerError;

    /** @var array<Throwable> */
    private array $collectedExceptions = [];

    /**
     * @param  array<callable>  $middleware
     * @param  ?callable(Throwable, WorkflowEvent): void  $onListenerError
     */
    public function __construct(
        private readonly WorkflowDefinition $definition,
        private readonly ?GuardEvaluatorInterface $guardEvaluator = null,
        array $middleware = [],
        ?ListenerErrorMode $listenerErrorMode = null,
        ?callable $onListenerError = null,
    ) {
        $this->middleware = $middleware;
        $this->listenerErrorMode = $listenerErrorMode ?? ListenerErrorMode::Throw;
        $this->onListenerError = $onListenerError !== null
            ? Closure::fromCallable($onListenerError)
            : null;
        $this->placeNames = array_flip(array_map(fn ($p) => $p->name, $definition->places));
        $this->marking = $this->buildInitialMarking();
    }

    public function use(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function getDefinition(): WorkflowDefinition
    {
        return $this->definition;
    }

    public function getMarking(): Marking
    {
        return $this->marking->clone();
    }

    public function setMarking(Marking $marking): void
    {
        $this->marking = $marking->clone();
    }

    public function getInitialMarking(): Marking
    {
        return $this->buildInitialMarking();
    }

    /**
     * @return array<string>
     */
    public function getActivePlaces(): array
    {
        return $this->marking->getActivePlaces();
    }

    /**
     * @return array<Transition>
     */
    public function getEnabledTransitions(): array
    {
        return array_values(array_filter(
            $this->definition->transitions,
            fn (Transition $t): bool => $this->can($t->name)->allowed,
        ));
    }

    public function can(string $transitionName): TransitionResult
    {
        $transition = $this->findTransition($transitionName);

        if ($transition === null) {
            return new TransitionResult(
                allowed: false,
                blockers: [new TransitionBlocker(
                    code: 'unknown_transition',
                    message: "Transition \"{$transitionName}\" does not exist",
                )],
            );
        }

        $blockers = [];

        if ($this->definition->type === \Laraflow\Enums\WorkflowType::StateMachine) {
            $activePlaces = $this->getActivePlaces();

            if (count($activePlaces) !== 1) {
                $blockers[] = new TransitionBlocker(
                    code: 'invalid_marking',
                    message: 'State machine must have exactly one active place, found ' . count($activePlaces),
                );

                return new TransitionResult(allowed: false, blockers: $blockers);
            }

            if (! in_array($activePlaces[0], $transition->froms, true)) {
                $blockers[] = new TransitionBlocker(
                    code: 'not_in_place',
                    message: "Current state \"{$activePlaces[0]}\" is not in transition's from places",
                );

                return new TransitionResult(allowed: false, blockers: $blockers);
            }
        } else {
            $weight = $transition->consumeWeight ?? 1;

            foreach ($transition->froms as $from) {
                if ($this->marking->get($from) < $weight) {
                    $blockers[] = new TransitionBlocker(
                        code: 'not_in_place',
                        message: "Place \"{$from}\" has {$this->marking->get($from)} token(s), needs {$weight}",
                    );
                }
            }
        }

        if (count($blockers) > 0) {
            return new TransitionResult(allowed: false, blockers: $blockers);
        }

        if ($transition->guard !== null && $this->guardEvaluator !== null) {
            $guardOutcome = $this->guardEvaluator->evaluate(
                $transition->guard,
                $this->getMarking(),
                $transition,
            );

            $guardResult = $guardOutcome instanceof GuardResult
                ? $guardOutcome
                : new GuardResult(allowed: $guardOutcome);

            if (! $guardResult->allowed) {
                $blockers[] = new TransitionBlocker(
                    code: $guardResult->code ?? 'guard_blocked',
                    message: $guardResult->reason ?? "Guard \"{$transition->guard}\" blocked the transition",
                );

                return new TransitionResult(allowed: false, blockers: $blockers);
            }
        }

        // Guard event — listeners may call $event->block(reason, code) to veto
        // the transition. Note this fires during can() (and therefore during
        // getEnabledTransitions()), so listeners should be idempotent.
        $guardEvent = $this->emitGuard($transition);

        if ($guardEvent->isBlocked()) {
            $blockers[] = new TransitionBlocker(
                code: $guardEvent->getBlockedCode() ?? 'guard_blocked',
                message: $guardEvent->getBlockedReason() ?? "Guard event blocked transition \"{$transition->name}\"",
            );

            return new TransitionResult(allowed: false, blockers: $blockers);
        }

        return new TransitionResult(allowed: true);
    }

    public function apply(string $transitionName): Marking
    {
        $result = $this->can($transitionName);

        if (! $result->allowed) {
            $messages = implode(', ', array_map(fn ($b) => $b->message, $result->blockers));

            throw new \RuntimeException(
                "Cannot apply transition \"{$transitionName}\": {$messages}",
            );
        }

        $transition = $this->findTransition($transitionName);
        assert($transition !== null);

        $this->collectedExceptions = [];

        if (count($this->middleware) === 0) {
            $marking = $this->applyCore($transition);
        } else {
            $context = new MiddlewareContext(
                definition: $this->definition,
                transition: $transition,
                marking: $this->getMarking(),
                workflowName: $this->definition->name,
            );

            $chain = array_reduce(
                array_reverse($this->middleware),
                fn (Closure $next, callable $mw): Closure => fn (): Marking => $mw($context, $next),
                fn (): Marking => $this->applyCore($transition),
            );

            $marking = $chain();
        }

        if (count($this->collectedExceptions) > 0) {
            $exceptions = $this->collectedExceptions;
            $this->collectedExceptions = [];

            throw new ListenerExceptionAggregate($exceptions);
        }

        return $marking;
    }

    public function reset(): void
    {
        $this->marking = $this->buildInitialMarking();
    }

    /**
     * Register a listener.
     *
     * @param  ?string  $transitionName  Restrict to one transition; null = wildcard (all transitions)
     * @param  int  $priority  Higher fires first; ties preserve registration order
     */
    public function on(
        WorkflowEventType $type,
        callable $listener,
        ?string $transitionName = null,
        int $priority = 0,
    ): Closure {
        $key = $type->value;
        $this->listeners[$key] ??= [];
        $this->listeners[$key][] = [
            'priority' => $priority,
            'scope' => $transitionName ?? '*',
            'cb' => $listener,
            'seq' => $this->listenerSeq++,
        ];

        return function () use ($key, $listener): void {
            $this->listeners[$key] = array_values(array_filter(
                $this->listeners[$key] ?? [],
                fn (array $entry): bool => $entry['cb'] !== $listener,
            ));
        };
    }

    private function applyCore(Transition $transition): Marking
    {
        // Guard event already fired during can() — do not re-emit here.

        // 2. Leave — fire per from-place, then remove tokens
        for ($i = 0; $i < count($transition->froms); $i++) {
            $this->emit(WorkflowEventType::Leave, $transition);
        }

        $cw = $transition->consumeWeight ?? 1;

        foreach ($transition->froms as $from) {
            $this->marking->set($from, max(0, $this->marking->get($from) - $cw));
        }

        // 3. Transition
        $this->emit(WorkflowEventType::Transition, $transition);

        // 4. Enter — fire per to-place (before marking update)
        for ($i = 0; $i < count($transition->tos); $i++) {
            $this->emit(WorkflowEventType::Enter, $transition);
        }

        // 5. Update marking
        $pw = $transition->produceWeight ?? 1;

        foreach ($transition->tos as $to) {
            $this->marking->set($to, $this->marking->get($to) + $pw);
        }

        // 6. Entered
        $this->emit(WorkflowEventType::Entered, $transition);

        // 7. Completed
        $this->emit(WorkflowEventType::Completed, $transition);

        // 8. Announce — fire for each newly enabled transition
        $enabled = $this->getEnabledTransitions();

        for ($i = 0; $i < count($enabled); $i++) {
            $this->emit(WorkflowEventType::Announce, $transition);
        }

        return $this->getMarking();
    }

    private function emit(WorkflowEventType $type, Transition $transition): void
    {
        $event = new WorkflowEvent(
            type: $type,
            transition: $transition,
            marking: $this->getMarking(),
            workflowName: $this->definition->name,
        );

        $this->dispatchToListeners($event);
    }

    private function emitGuard(Transition $transition): GuardEvent
    {
        $event = new GuardEvent(
            type: WorkflowEventType::Guard,
            transition: $transition,
            marking: $this->getMarking(),
            workflowName: $this->definition->name,
        );

        $this->dispatchToListeners($event);

        return $event;
    }

    private function dispatchToListeners(WorkflowEvent $event): void
    {
        $candidates = array_filter(
            $this->listeners[$event->type->value] ?? [],
            fn (array $entry): bool => $entry['scope'] === '*' || $entry['scope'] === $event->transition->name,
        );

        if ($candidates === []) {
            return;
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority']; // higher priority first
            }

            return $a['seq'] <=> $b['seq']; // FIFO within same priority
        });

        foreach ($candidates as $entry) {
            try {
                ($entry['cb'])($event);
            } catch (Throwable $e) {
                match ($this->listenerErrorMode) {
                    ListenerErrorMode::Throw => throw $e,
                    ListenerErrorMode::Collect => $this->collectedExceptions[] = $e,
                    ListenerErrorMode::Swallow => $this->onListenerError !== null
                        ? ($this->onListenerError)($e, $event)
                        : null,
                };
            }
        }
    }

    private function findTransition(string $name): ?Transition
    {
        foreach ($this->definition->transitions as $transition) {
            if ($transition->name === $name) {
                return $transition;
            }
        }

        return null;
    }

    private function buildInitialMarking(): Marking
    {
        $marking = new Marking();

        foreach ($this->definition->places as $place) {
            $marking->set($place->name, 0);
        }

        foreach ($this->definition->initialMarking as $place) {
            if (isset($this->placeNames[$place])) {
                $marking->set($place, 1);
            }
        }

        return $marking;
    }
}

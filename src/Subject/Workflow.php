<?php

declare(strict_types=1);

namespace Laraflow\Subject;

use Closure;
use Laraflow\Contracts\GuardEvaluatorInterface;
use Laraflow\Contracts\MarkingStoreInterface;
use Laraflow\Data\GuardEvent;
use Laraflow\Data\Marking;
use Laraflow\Data\MiddlewareContext;
use Laraflow\Data\SubjectEvent;
use Laraflow\Data\SubjectGuardEvent;
use Laraflow\Data\SubjectMiddlewareContext;
use Laraflow\Data\Transition;
use Laraflow\Data\TransitionResult;
use Laraflow\Data\WorkflowDefinition;
use Laraflow\Data\WorkflowEvent;
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Enums\ListenerErrorMode;
use Laraflow\Enums\WorkflowEventType;
use Throwable;

class Workflow
{
    /** @var array<string, array<int, array{priority: int, scope: string, cb: callable}>> */
    private array $listeners = [];

    /** @var array<callable> */
    private array $subjectMiddleware;

    private readonly ?ListenerErrorMode $listenerErrorMode;

    /** @var ?callable(Throwable, WorkflowEvent): void */
    private $onListenerError;

    /**
     * @param  array<callable>  $middleware
     * @param  ?callable(Throwable, WorkflowEvent): void  $onListenerError
     */
    public function __construct(
        public readonly WorkflowDefinition $definition,
        private readonly MarkingStoreInterface $markingStore,
        private readonly ?GuardEvaluatorInterface $guardEvaluator = null,
        array $middleware = [],
        ?ListenerErrorMode $listenerErrorMode = null,
        ?callable $onListenerError = null,
    ) {
        $this->subjectMiddleware = $middleware;
        $this->listenerErrorMode = $listenerErrorMode;
        $this->onListenerError = $onListenerError;
    }

    public function use(callable $middleware): void
    {
        $this->subjectMiddleware[] = $middleware;
    }

    public function getMarking(object $subject): Marking
    {
        return $this->markingStore->read($subject);
    }

    public function setMarking(object $subject, Marking $marking): void
    {
        $this->markingStore->write($subject, $marking);
    }

    /**
     * @return array<Transition>
     */
    public function getEnabledTransitions(object $subject): array
    {
        $engine = $this->buildEngine($subject);
        $this->attachSubjectListeners($engine, $subject);

        return $engine->getEnabledTransitions();
    }

    public function can(object $subject, string $transitionName): TransitionResult
    {
        $engine = $this->buildEngine($subject);
        $this->attachSubjectListeners($engine, $subject);

        return $engine->can($transitionName);
    }

    public function apply(object $subject, string $transitionName): Marking
    {
        $engine = $this->buildEngine($subject);

        // Convert subject middleware to engine middleware
        foreach ($this->subjectMiddleware as $mw) {
            $engine->use(function (MiddlewareContext $ctx, Closure $next) use ($mw, $subject): Marking {
                $subjectCtx = new SubjectMiddlewareContext(
                    definition: $ctx->definition,
                    transition: $ctx->transition,
                    marking: $ctx->marking,
                    workflowName: $ctx->workflowName,
                    subject: $subject,
                );

                return $mw($subjectCtx, $next);
            });
        }

        $this->attachSubjectListeners($engine, $subject);

        $newMarking = $engine->apply($transitionName);
        $this->markingStore->write($subject, $newMarking);

        return $newMarking;
    }

    private function attachSubjectListeners(WorkflowEngine $engine, object $subject): void
    {
        foreach ($this->listeners as $typeValue => $typeListeners) {
            $eventType = WorkflowEventType::from($typeValue);
            foreach ($typeListeners as $entry) {
                $listener = $entry['cb'];
                $engine->on(
                    $eventType,
                    $this->wrapSubjectListener($listener, $subject),
                    $entry['scope'] === '*' ? null : $entry['scope'],
                    $entry['priority'],
                );
            }
        }
    }

    private function wrapSubjectListener(callable $listener, object $subject): Closure
    {
        return function (WorkflowEvent $event) use ($listener, $subject): void {
            if ($event instanceof GuardEvent) {
                $subjectEvent = new SubjectGuardEvent(
                    type: $event->type,
                    transition: $event->transition,
                    marking: $event->marking,
                    workflowName: $event->workflowName,
                    subject: $subject,
                );

                // Mirror prior listeners' blocked state so this listener can
                // observe it, matching the engine-layer dispatch where every
                // listener receives the same GuardEvent instance.
                if ($event->isBlocked()) {
                    $subjectEvent->block(
                        $event->getBlockedReason() ?? '',
                        $event->getBlockedCode(),
                    );
                }

                $listener($subjectEvent);

                if ($subjectEvent->isBlocked()) {
                    $event->block(
                        $subjectEvent->getBlockedReason() ?? '',
                        $subjectEvent->getBlockedCode(),
                    );
                }

                return;
            }

            $subjectEvent = new SubjectEvent(
                type: $event->type,
                transition: $event->transition,
                marking: $event->marking,
                workflowName: $event->workflowName,
                subject: $subject,
            );

            $listener($subjectEvent);
        };
    }

    /**
     * Register a subject-aware listener.
     *
     * @param  ?string  $transitionName  Restrict to one transition; null = wildcard
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
        ];

        return function () use ($key, $listener): void {
            $this->listeners[$key] = array_values(array_filter(
                $this->listeners[$key] ?? [],
                fn (array $entry): bool => $entry['cb'] !== $listener,
            ));
        };
    }

    private function buildEngine(object $subject): WorkflowEngine
    {
        $guardEvaluator = $this->guardEvaluator;

        $engine = new WorkflowEngine(
            definition: $this->definition,
            guardEvaluator: $guardEvaluator,
            listenerErrorMode: $this->listenerErrorMode,
            onListenerError: $this->onListenerError,
        );

        $engine->setMarking($this->markingStore->read($subject));

        return $engine;
    }
}

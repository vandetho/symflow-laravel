<?php

declare(strict_types=1);

use Laraflow\Contracts\GuardEvaluatorInterface;
use Laraflow\Data\GuardEvent;
use Laraflow\Data\GuardResult;
use Laraflow\Data\Marking;
use Laraflow\Data\Transition;
use Laraflow\Data\WorkflowEvent;
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Enums\ListenerErrorMode;
use Laraflow\Enums\WorkflowEventType;
use Laraflow\Exceptions\ListenerExceptionAggregate;
use Laraflow\Tests\Fixtures\Definitions;

// --- State Machine ---

test('starts with initial marking', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    expect($engine->getActivePlaces())->toBe(['draft']);
});

test('can() returns allowed for valid transition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $result = $engine->can('submit');
    expect($result->allowed)->toBeTrue();
    expect($result->blockers)->toBe([]);
});

test('can() returns not_in_place for wrong state', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $result = $engine->can('approve');
    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('not_in_place');
});

test('can() returns unknown_transition for nonexistent transition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $result = $engine->can('nonexistent');
    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('unknown_transition');
});

test('apply() moves to new state', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->apply('submit');
    expect($engine->getActivePlaces())->toBe(['submitted']);
});

test('apply() throws on blocked transition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    expect(fn () => $engine->apply('approve'))->toThrow(RuntimeException::class, 'Cannot apply transition');
});

test('getEnabledTransitions() returns correct transitions', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->apply('submit');
    $names = array_map(fn (Transition $t) => $t->name, $engine->getEnabledTransitions());
    sort($names);
    expect($names)->toBe(['approve', 'reject']);
});

test('reset() restores initial marking', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->apply('submit');
    $engine->reset();
    expect($engine->getActivePlaces())->toBe(['draft']);
});

test('full path: draft to fulfilled', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->apply('submit');
    $engine->apply('approve');
    $engine->apply('fulfill');
    expect($engine->getActivePlaces())->toBe(['fulfilled']);
    expect($engine->getEnabledTransitions())->toBe([]);
});

test('setMarking() overrides current state', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $marking = new Marking(['draft' => 0, 'submitted' => 0, 'approved' => 1, 'rejected' => 0, 'fulfilled' => 0]);
    $engine->setMarking($marking);
    expect($engine->getActivePlaces())->toBe(['approved']);
    expect($engine->can('fulfill')->allowed)->toBeTrue();
});

test('getDefinition() returns the definition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    expect($engine->getDefinition()->name)->toBe('order');
});

test('getInitialMarking() always returns initial state', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->apply('submit');
    $initial = $engine->getInitialMarking();
    expect($initial->get('draft'))->toBe(1);
    expect($initial->get('submitted'))->toBe(0);
});

// --- Petri Net ---

test('workflow: AND-split forks into parallel places', function () {
    $engine = new WorkflowEngine(Definitions::articleReviewWorkflow());
    $engine->apply('start_review');
    $places = $engine->getActivePlaces();
    sort($places);
    expect($places)->toBe(['checking_content', 'checking_spelling']);
});

test('workflow: AND-join requires all source places', function () {
    $engine = new WorkflowEngine(Definitions::articleReviewWorkflow());
    $engine->apply('start_review');
    $engine->apply('approve_content');
    expect($engine->can('publish')->allowed)->toBeFalse();
});

test('workflow: AND-join fires when all sources marked', function () {
    $engine = new WorkflowEngine(Definitions::articleReviewWorkflow());
    $engine->apply('start_review');
    $engine->apply('approve_content');
    $engine->apply('approve_spelling');
    expect($engine->can('publish')->allowed)->toBeTrue();
    $engine->apply('publish');
    expect($engine->getActivePlaces())->toBe(['published']);
});

test('workflow: tokens consumed correctly', function () {
    $engine = new WorkflowEngine(Definitions::articleReviewWorkflow());
    $engine->apply('start_review');
    $marking = $engine->getMarking();
    expect($marking->get('draft'))->toBe(0);
    expect($marking->get('checking_content'))->toBe(1);
    expect($marking->get('checking_spelling'))->toBe(1);
});

// --- Guards ---

test('guard: blocks transition when guard fails', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): bool
        {
            return false;
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    $result = $engine->can('approve');
    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('guard_blocked');
});

test('guard: allows transition when guard passes', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): bool
        {
            return true;
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    expect($engine->can('approve')->allowed)->toBeTrue();
});

test('guard: transitions without guards are not affected', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): bool
        {
            return false;
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    expect($engine->can('deny')->allowed)->toBeTrue();
});

test('guard: GuardResult::deny propagates reason and code into blocker', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): GuardResult
        {
            return GuardResult::deny('User is not an admin', 'not_admin');
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    $result = $engine->can('approve');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('not_admin');
    expect($result->blockers[0]->message)->toBe('User is not an admin');
});

test('guard: GuardResult::deny without code falls back to guard_blocked', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): GuardResult
        {
            return GuardResult::deny('Outside business hours');
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    $result = $engine->can('approve');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('guard_blocked');
    expect($result->blockers[0]->message)->toBe('Outside business hours');
});

test('guard: GuardResult::allow permits the transition', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): GuardResult
        {
            return GuardResult::allow();
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);
    expect($engine->can('approve')->allowed)->toBeTrue();
});

// --- Events ---

test('events: fires in Symfony order', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $events = [];

    $engine->on(\Laraflow\Enums\WorkflowEventType::Guard, function () use (&$events) { $events[] = 'guard'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Leave, function () use (&$events) { $events[] = 'leave'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Transition, function () use (&$events) { $events[] = 'transition'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Enter, function () use (&$events) { $events[] = 'enter'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Entered, function () use (&$events) { $events[] = 'entered'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Completed, function () use (&$events) { $events[] = 'completed'; });
    $engine->on(\Laraflow\Enums\WorkflowEventType::Announce, function () use (&$events) { $events[] = 'announce'; });

    $engine->apply('submit');

    expect($events[0])->toBe('guard');
    expect($events[1])->toBe('leave');
    expect($events[2])->toBe('transition');
    expect($events[3])->toBe('enter');
    expect($events[4])->toBe('entered');
    expect($events[5])->toBe('completed');
});

test('events: event contains correct data', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $receivedEvent = null;

    $engine->on(\Laraflow\Enums\WorkflowEventType::Entered, function (WorkflowEvent $event) use (&$receivedEvent) {
        $receivedEvent = $event;
    });

    $engine->apply('submit');

    expect($receivedEvent)->not->toBeNull();
    expect($receivedEvent->type)->toBe(\Laraflow\Enums\WorkflowEventType::Entered);
    expect($receivedEvent->transition->name)->toBe('submit');
    expect($receivedEvent->workflowName)->toBe('order');
});

test('events: unsubscribe removes listener', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $called = false;
    $unsub = $engine->on(\Laraflow\Enums\WorkflowEventType::Entered, function () use (&$called) { $called = true; });
    $unsub();
    $engine->apply('submit');
    expect($called)->toBeFalse();
});

// --- Listener error containment ---

test('listener errors: Throw mode rethrows (default behavior)', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Enter, function () {
        throw new \RuntimeException('boom');
    });

    expect(fn () => $engine->apply('submit'))
        ->toThrow(\RuntimeException::class, 'boom');
});

test('listener errors: Throw mode leaves marking inconsistent (regression-doc)', function () {
    // This test documents the bug that motivates the Collect/Swallow modes:
    // when a listener throws between Leave and the marking-set, tokens are
    // already removed from the source place but never added to the target.
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Enter, function () {
        throw new \RuntimeException('boom');
    });

    try {
        $engine->apply('submit');
    } catch (\RuntimeException) {
        // expected
    }

    $marking = $engine->getMarking();
    expect($marking->get('draft'))->toBe(0);     // token removed
    expect($marking->get('submitted'))->toBe(0); // token never added
});

test('listener errors: Collect mode completes the transition then throws aggregate', function () {
    $engine = new WorkflowEngine(
        definition: Definitions::orderStateMachine(),
        listenerErrorMode: ListenerErrorMode::Collect,
    );
    $engine->on(WorkflowEventType::Enter, function () {
        throw new \RuntimeException('first');
    });
    $engine->on(WorkflowEventType::Entered, function () {
        throw new \RuntimeException('second');
    });

    try {
        $engine->apply('submit');
        $thrown = null;
    } catch (ListenerExceptionAggregate $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ListenerExceptionAggregate::class);
    expect($thrown->getExceptions())->toHaveCount(2);
    expect($thrown->getExceptions()[0]->getMessage())->toBe('first');
    expect($thrown->getExceptions()[1]->getMessage())->toBe('second');

    // Marking is consistent — token moved fully to 'submitted'.
    $marking = $engine->getMarking();
    expect($marking->get('draft'))->toBe(0);
    expect($marking->get('submitted'))->toBe(1);
});

test('listener errors: Collect mode without exceptions does not throw', function () {
    $engine = new WorkflowEngine(
        definition: Definitions::orderStateMachine(),
        listenerErrorMode: ListenerErrorMode::Collect,
    );
    $engine->on(WorkflowEventType::Enter, function () { /* no-op */ });

    $marking = $engine->apply('submit');
    expect($marking->get('submitted'))->toBe(1);
});

test('listener errors: Swallow mode invokes onListenerError and continues', function () {
    $captured = [];
    $engine = new WorkflowEngine(
        definition: Definitions::orderStateMachine(),
        listenerErrorMode: ListenerErrorMode::Swallow,
        onListenerError: function (\Throwable $e, WorkflowEvent $event) use (&$captured) {
            $captured[] = ['msg' => $e->getMessage(), 'type' => $event->type];
        },
    );
    $engine->on(WorkflowEventType::Enter, function () {
        throw new \RuntimeException('shh');
    });

    $marking = $engine->apply('submit');
    expect($marking->get('submitted'))->toBe(1);
    expect($captured)->toHaveCount(1);
    expect($captured[0]['msg'])->toBe('shh');
    expect($captured[0]['type'])->toBe(WorkflowEventType::Enter);
});

test('listener errors: Swallow mode without callback silently ignores', function () {
    $engine = new WorkflowEngine(
        definition: Definitions::orderStateMachine(),
        listenerErrorMode: ListenerErrorMode::Swallow,
    );
    $engine->on(WorkflowEventType::Enter, function () {
        throw new \RuntimeException('shh');
    });

    $marking = $engine->apply('submit');
    expect($marking->get('submitted'))->toBe(1);
});

test('listener errors: subsequent listeners still run in Collect mode', function () {
    $ran = [];
    $engine = new WorkflowEngine(
        definition: Definitions::orderStateMachine(),
        listenerErrorMode: ListenerErrorMode::Collect,
    );
    $engine->on(WorkflowEventType::Enter, function () { throw new \RuntimeException('a'); });
    $engine->on(WorkflowEventType::Enter, function () use (&$ran) { $ran[] = 'second'; });

    try { $engine->apply('submit'); } catch (ListenerExceptionAggregate) {}

    expect($ran)->toBe(['second']);
});

// --- Listener scoping & priority ---

test('listener scope: scoped listener fires only for its transition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $calls = [];
    $engine->on(
        WorkflowEventType::Entered,
        function (WorkflowEvent $e) use (&$calls) { $calls[] = $e->transition->name; },
        transitionName: 'submit',
    );

    $engine->apply('submit');   // matches
    $engine->apply('approve');  // does not match
    $engine->apply('fulfill');  // does not match

    expect($calls)->toBe(['submit']);
});

test('listener scope: wildcard listener fires for every transition', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $calls = [];
    $engine->on(
        WorkflowEventType::Entered,
        function (WorkflowEvent $e) use (&$calls) { $calls[] = $e->transition->name; },
    );

    $engine->apply('submit');
    $engine->apply('approve');

    expect($calls)->toBe(['submit', 'approve']);
});

test('listener priority: higher priority fires first', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $order = [];
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'low'; }, priority: 1);
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'high'; }, priority: 100);
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'mid'; }, priority: 50);

    $engine->apply('submit');

    expect($order)->toBe(['high', 'mid', 'low']);
});

test('listener priority: ties preserve registration order', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $order = [];
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'first'; });
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'second'; });
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'third'; });

    $engine->apply('submit');

    expect($order)->toBe(['first', 'second', 'third']);
});

test('listener priority: scoped and wildcard interleave by priority globally', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $order = [];

    // Registration order intentionally does NOT match expected fire order;
    // priority must dominate, with FIFO breaking ties across scopes.
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'wildcard@10'; }, priority: 10);
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'scoped@100'; }, transitionName: 'submit', priority: 100);
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'wildcard@50'; }, priority: 50);
    $engine->on(WorkflowEventType::Entered, function () use (&$order) { $order[] = 'scoped@5'; }, transitionName: 'submit', priority: 5);

    $engine->apply('submit');

    expect($order)->toBe(['scoped@100', 'wildcard@50', 'wildcard@10', 'scoped@5']);
});

test('listener scope: unsubscribe removes listener regardless of scope/priority', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $calls = [];
    $listener = function () use (&$calls) { $calls[] = 'fired'; };

    $unsub = $engine->on(WorkflowEventType::Entered, $listener, transitionName: 'submit', priority: 5);
    $unsub();
    $engine->apply('submit');

    expect($calls)->toBe([]);
});

// --- Blockable Guard event ---

test('guard event: listener can block transition with reason and code', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        $event->block('Customer has overdue invoices', 'overdue_invoices');
    });

    $result = $engine->can('submit');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('overdue_invoices');
    expect($result->blockers[0]->message)->toBe('Customer has overdue invoices');
});

test('guard event: block reason without code falls back to guard_blocked', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        $event->block('Outside business hours');
    });

    $result = $engine->can('submit');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('guard_blocked');
    expect($result->blockers[0]->message)->toBe('Outside business hours');
});

test('guard event: non-blocking listener still observes (BC)', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $observed = [];
    $engine->on(WorkflowEventType::Guard, function (WorkflowEvent $event) use (&$observed) {
        $observed[] = $event->transition->name;
    });

    expect($engine->can('submit')->allowed)->toBeTrue();
    expect($observed)->toBe(['submit']);
});

test('guard event: apply() throws when a guard listener blocks', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        $event->block('nope', 'denied');
    });

    expect(fn () => $engine->apply('submit'))
        ->toThrow(\RuntimeException::class, 'nope');
});

test('guard event: getEnabledTransitions respects blocking listeners', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        if ($event->transition->name === 'submit') {
            $event->block('blocked');
        }
    });

    expect($engine->getEnabledTransitions())->toBe([]);
});

test('guard event: when multiple listeners block, last writer wins', function () {
    // Documented contract: block() simply overwrites prior block state.
    // Listeners with conflicting reasons should not be relied on for
    // ordering — use priority + a single source of truth.
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        $event->block('first', 'first_code');
    }, priority: 100); // fires first
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) {
        $event->block('second', 'second_code');
    }, priority: 50); // fires after, overwrites

    $result = $engine->can('submit');

    expect($result->blockers[0]->code)->toBe('second_code');
    expect($result->blockers[0]->message)->toBe('second');
});

test('guard event: GuardEvaluator denial short-circuits before event fires', function () {
    $evaluator = new class implements GuardEvaluatorInterface {
        public function evaluate(string $expression, Marking $marking, Transition $transition): bool
        {
            return false;
        }
    };
    $engine = new WorkflowEngine(Definitions::guardedStateMachine(), $evaluator);

    $eventFired = false;
    $engine->on(WorkflowEventType::Guard, function () use (&$eventFired) {
        $eventFired = true;
    });

    $result = $engine->can('approve');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('guard_blocked');
    expect($eventFired)->toBeFalse();
});

test('guard event: fires once when can() is called directly', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $count = 0;
    $engine->on(WorkflowEventType::Guard, function () use (&$count) {
        $count++;
    }, transitionName: 'submit');

    $engine->can('submit');

    expect($count)->toBe(1);
});

test('guard event: also fires during getEnabledTransitions (idempotency required)', function () {
    // Documented behavior: Guard listeners fire for every can() check, which
    // includes the per-transition checks getEnabledTransitions() performs.
    // Listeners must be idempotent and side-effect-free.
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $checked = [];
    $engine->on(WorkflowEventType::Guard, function (GuardEvent $event) use (&$checked) {
        $checked[] = $event->transition->name;
    });

    $engine->getEnabledTransitions();

    // Only 'submit' is structurally enabled from initial 'draft' state, so
    // Guard fires only for it (other transitions are blocked earlier on
    // place check before reaching the Guard event).
    expect($checked)->toBe(['submit']);
});

test('weighted: can() returns false when marking < consumeWeight', function () {
    $engine = new WorkflowEngine(Definitions::weightedWorkflow());
    $result = $engine->can('manufacture');
    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('not_in_place');
});

test('weighted: can() returns true when marking >= consumeWeight', function () {
    $engine = new WorkflowEngine(Definitions::weightedWorkflow());
    $engine->setMarking(new Marking(['raw_materials' => 3, 'components' => 0, 'assembled' => 0]));
    expect($engine->can('manufacture')->allowed)->toBeTrue();
});

test('weighted: apply() consumes and produces correct token counts', function () {
    $engine = new WorkflowEngine(Definitions::weightedWorkflow());
    $engine->setMarking(new Marking(['raw_materials' => 5, 'components' => 0, 'assembled' => 0]));
    $engine->apply('manufacture');
    $marking = $engine->getMarking();
    expect($marking->get('raw_materials'))->toBe(2);
    expect($marking->get('components'))->toBe(2);
});

test('weighted: multi-step accumulation', function () {
    $engine = new WorkflowEngine(Definitions::weightedWorkflow());
    $engine->setMarking(new Marking(['raw_materials' => 6, 'components' => 0, 'assembled' => 0]));
    $engine->apply('manufacture');
    $engine->apply('manufacture');
    expect($engine->can('assemble')->allowed)->toBeTrue();
    $engine->apply('assemble');
    $marking = $engine->getMarking();
    expect($marking->get('raw_materials'))->toBe(0);
    expect($marking->get('components'))->toBe(2);
    expect($marking->get('assembled'))->toBe(1);
});

// --- Middleware ---

test('middleware: called with correct context', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $receivedCtx = null;

    $engine->use(function ($ctx, $next) use (&$receivedCtx) {
        $receivedCtx = $ctx;
        return $next();
    });

    $engine->apply('submit');
    expect($receivedCtx)->not->toBeNull();
    expect($receivedCtx->transition->name)->toBe('submit');
    expect($receivedCtx->workflowName)->toBe('order');
});

test('middleware: chain executes in registration order', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $log = [];

    $engine->use(function ($ctx, $next) use (&$log) {
        $log[] = 'mw1-before';
        $result = $next();
        $log[] = 'mw1-after';
        return $result;
    });

    $engine->use(function ($ctx, $next) use (&$log) {
        $log[] = 'mw2-before';
        $result = $next();
        $log[] = 'mw2-after';
        return $result;
    });

    $engine->apply('submit');
    expect($log)->toBe(['mw1-before', 'mw2-before', 'mw2-after', 'mw1-after']);
});

test('middleware: can block transition by not calling next', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());

    $engine->use(function ($ctx, $next) {
        return $ctx->marking;
    });

    $result = $engine->apply('submit');
    expect($result->get('draft'))->toBe(1);
    expect($engine->getActivePlaces())->toBe(['draft']);
});

test('middleware: can() is not affected by middleware', function () {
    $engine = new WorkflowEngine(Definitions::orderStateMachine());
    $called = false;

    $engine->use(function ($ctx, $next) use (&$called) {
        $called = true;
        return $next();
    });

    $engine->can('submit');
    expect($called)->toBeFalse();
});

<?php

declare(strict_types=1);

use Laraflow\Data\Marking;
use Laraflow\Data\SubjectEvent;
use Laraflow\Data\SubjectGuardEvent;
use Laraflow\Enums\WorkflowEventType;
use Laraflow\Subject\PropertyMarkingStore;
use Laraflow\Subject\Workflow;
use Laraflow\Tests\Fixtures\Definitions;

test('can() checks transition for subject', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    expect($workflow->can($order, 'submit')->allowed)->toBeTrue();
    expect($workflow->can($order, 'approve')->allowed)->toBeFalse();
});

test('apply() updates subject state', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $workflow->apply($order, 'submit');
    expect($order->status)->toBe('submitted');
});

test('getEnabledTransitions() returns available transitions', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'submitted';
    $names = array_map(fn ($t) => $t->name, $workflow->getEnabledTransitions($order));
    sort($names);
    expect($names)->toBe(['approve', 'reject']);
});

test('getMarking() reads from subject', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'approved';
    $marking = $workflow->getMarking($order);
    expect($marking->get('approved'))->toBe(1);
});

test('events include subject', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $order->id = '1';

    $receivedSubject = null;
    $workflow->on(WorkflowEventType::Entered, function (SubjectEvent $event) use (&$receivedSubject) {
        $receivedSubject = $event->subject;
    });

    $workflow->apply($order, 'submit');
    expect($receivedSubject)->toBe($order);
});

test('subject guard event: listener can block based on subject state', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $order->customerHasOverdueInvoice = true;

    $workflow->on(WorkflowEventType::Guard, function (SubjectGuardEvent $event) {
        if ($event->subject->customerHasOverdueInvoice) {
            $event->block('Customer has overdue invoices', 'overdue_invoices');
        }
    });

    $result = $workflow->can($order, 'submit');

    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('overdue_invoices');
    expect($result->blockers[0]->message)->toBe('Customer has overdue invoices');
});

test('subject guard event: getEnabledTransitions reflects subject-side blocking', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $order->isFrozen = true;

    $workflow->on(WorkflowEventType::Guard, function (SubjectGuardEvent $event) {
        if ($event->subject->isFrozen) {
            $event->block('account frozen');
        }
    });

    expect($workflow->getEnabledTransitions($order))->toBe([]);
});

test('subject guard event: apply throws when blocked', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';

    $workflow->on(WorkflowEventType::Guard, function (SubjectGuardEvent $event) {
        $event->block('subject says no');
    });

    expect(fn () => $workflow->apply($order, 'submit'))
        ->toThrow(\RuntimeException::class, 'subject says no');
});

test('subject listener: scope and priority forward to engine', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $order2 = new stdClass();
    $order2->status = 'submitted';

    $order = (function () {
        $o = new stdClass();
        $o->status = 'draft';
        return $o;
    })();

    $calls = [];

    // wildcard low priority
    $workflow->on(WorkflowEventType::Entered, function (SubjectEvent $e) use (&$calls) {
        $calls[] = 'wild';
    }, priority: 1);

    // scoped high priority — must fire first when transition matches
    $workflow->on(WorkflowEventType::Entered, function (SubjectEvent $e) use (&$calls) {
        $calls[] = 'scoped-submit';
    }, transitionName: 'submit', priority: 100);

    // scoped to a different transition — must NOT fire on submit
    $workflow->on(WorkflowEventType::Entered, function (SubjectEvent $e) use (&$calls) {
        $calls[] = 'scoped-approve';
    }, transitionName: 'approve');

    $workflow->apply($order, 'submit');

    expect($calls)->toBe(['scoped-submit', 'wild']);
});

test('full flow through subject', function () {
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $order = new stdClass();
    $order->status = 'draft';
    $workflow->apply($order, 'submit');
    $workflow->apply($order, 'approve');
    $workflow->apply($order, 'fulfill');
    expect($order->status)->toBe('fulfilled');
});

test('subject middleware receives subject in context', function () {
    $workflow = new Workflow(
        Definitions::orderStateMachine(),
        new PropertyMarkingStore('status'),
        middleware: [
            function ($ctx, $next) {
                expect($ctx->subject)->toBeObject();
                expect($ctx->subject->id)->toBe('1');
                return $next();
            },
        ],
    );
    $order = new stdClass();
    $order->status = 'draft';
    $order->id = '1';
    $workflow->apply($order, 'submit');
});

test('subject middleware wraps the transition', function () {
    $log = [];
    $workflow = new Workflow(
        Definitions::orderStateMachine(),
        new PropertyMarkingStore('status'),
        middleware: [
            function ($ctx, $next) use (&$log) {
                $log[] = 'before';
                $result = $next();
                $log[] = 'after';
                return $result;
            },
        ],
    );
    $order = new stdClass();
    $order->status = 'draft';
    $workflow->apply($order, 'submit');
    expect($log)->toBe(['before', 'after']);
});

test('use() adds middleware at runtime', function () {
    $log = [];
    $workflow = new Workflow(Definitions::orderStateMachine(), new PropertyMarkingStore('status'));
    $workflow->use(function ($ctx, $next) use (&$log) {
        $log[] = 'runtime-mw';
        return $next();
    });
    $order = new stdClass();
    $order->status = 'draft';
    $workflow->apply($order, 'submit');
    expect($log)->toBe(['runtime-mw']);
});

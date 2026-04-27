<?php

declare(strict_types=1);

use Laraflow\Contracts\WorkflowRegistryInterface;
use Laraflow\Data\Marking;
use Laraflow\Data\Transition;
use Laraflow\Eloquent\HasWorkflowTrait;

beforeEach(function () {
    config()->set('laraflow.workflows', [
        'order' => [
            'type' => 'state_machine',
            'marking_store' => ['type' => 'property', 'property' => 'status'],
            'initial_marking' => ['draft'],
            'places' => ['draft', 'submitted', 'approved', 'rejected', 'fulfilled'],
            'transitions' => [
                'submit' => ['from' => 'draft', 'to' => 'submitted'],
                'approve' => ['from' => 'submitted', 'to' => 'approved'],
                'reject' => ['from' => 'submitted', 'to' => 'rejected'],
                'fulfill' => ['from' => 'approved', 'to' => 'fulfilled'],
            ],
        ],
        'shipping' => [
            'type' => 'state_machine',
            'marking_store' => ['type' => 'property', 'property' => 'shipping_status'],
            'initial_marking' => ['pending'],
            'places' => ['pending', 'shipped', 'delivered'],
            'transitions' => [
                'ship' => ['from' => 'pending', 'to' => 'shipped'],
                'deliver' => ['from' => 'shipped', 'to' => 'delivered'],
            ],
        ],
    ]);

    app()->forgetInstance(WorkflowRegistryInterface::class);
});

function makeOrderSubject(string $status = 'draft', string $shippingStatus = 'pending'): object
{
    return new class($status, $shippingStatus)
    {
        use HasWorkflowTrait;

        public string $status;
        public string $shipping_status;

        public function __construct(string $status, string $shippingStatus)
        {
            $this->status = $status;
            $this->shipping_status = $shippingStatus;
        }

        protected function getDefaultWorkflowName(): string
        {
            return 'order';
        }
    };
}

test('workflow() resolves the default workflow', function () {
    $order = makeOrderSubject();
    $workflow = $order->workflow();
    expect($workflow->definition->name)->toBe('order');
});

test('workflow() resolves a named workflow', function () {
    $order = makeOrderSubject();
    $workflow = $order->workflow('shipping');
    expect($workflow->definition->name)->toBe('shipping');
});

test('canTransition() returns true for allowed transitions', function () {
    $order = makeOrderSubject('draft');
    expect($order->canTransition('submit'))->toBeTrue();
});

test('canTransition() returns false for blocked transitions', function () {
    $order = makeOrderSubject('draft');
    expect($order->canTransition('approve'))->toBeFalse();
});

test('canTransition() respects named workflow argument', function () {
    $order = makeOrderSubject(shippingStatus: 'pending');
    expect($order->canTransition('ship', 'shipping'))->toBeTrue();
    expect($order->canTransition('deliver', 'shipping'))->toBeFalse();
});

test('applyTransition() updates subject state and returns marking', function () {
    $order = makeOrderSubject('draft');
    $marking = $order->applyTransition('submit');

    expect($order->status)->toBe('submitted');
    expect($marking)->toBeInstanceOf(Marking::class);
    expect($marking->get('submitted'))->toBe(1);
});

test('applyTransition() works across the full lifecycle', function () {
    $order = makeOrderSubject('draft');
    $order->applyTransition('submit');
    $order->applyTransition('approve');
    $order->applyTransition('fulfill');
    expect($order->status)->toBe('fulfilled');
});

test('applyTransition() respects named workflow argument', function () {
    $order = makeOrderSubject(shippingStatus: 'pending');
    $order->applyTransition('ship', 'shipping');
    expect($order->shipping_status)->toBe('shipped');
});

test('getEnabledTransitions() lists currently allowed transitions', function () {
    $order = makeOrderSubject('submitted');
    $names = array_map(
        fn (Transition $t): string => $t->name,
        $order->getEnabledTransitions(),
    );
    sort($names);
    expect($names)->toBe(['approve', 'reject']);
});

test('getEnabledTransitions() is empty for a terminal state', function () {
    $order = makeOrderSubject('fulfilled');
    expect($order->getEnabledTransitions())->toBe([]);
});

test('getWorkflowMarking() reads from subject', function () {
    $order = makeOrderSubject('approved');
    $marking = $order->getWorkflowMarking();
    expect($marking->get('approved'))->toBe(1);
    expect($marking->get('draft'))->toBe(0);
});

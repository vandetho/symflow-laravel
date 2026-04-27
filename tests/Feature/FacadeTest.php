<?php

declare(strict_types=1);

use Laraflow\Contracts\WorkflowRegistryInterface;
use Laraflow\Facades\Laraflow;
use Laraflow\Subject\Workflow;

beforeEach(function () {
    config()->set('laraflow.workflows', [
        'order' => [
            'type' => 'state_machine',
            'marking_store' => ['type' => 'property', 'property' => 'status'],
            'initial_marking' => ['draft'],
            'places' => ['draft', 'submitted', 'approved'],
            'transitions' => [
                'submit' => ['from' => 'draft', 'to' => 'submitted'],
                'approve' => ['from' => 'submitted', 'to' => 'approved'],
            ],
        ],
    ]);

    app()->forgetInstance(WorkflowRegistryInterface::class);
});

test('facade resolves to the registry', function () {
    expect(Laraflow::getFacadeRoot())->toBeInstanceOf(WorkflowRegistryInterface::class);
});

test('facade has() reports configured workflows', function () {
    expect(Laraflow::has('order'))->toBeTrue();
    expect(Laraflow::has('missing'))->toBeFalse();
});

test('facade get() returns a Workflow instance', function () {
    $workflow = Laraflow::get('order');
    expect($workflow)->toBeInstanceOf(Workflow::class);
    expect($workflow->definition->name)->toBe('order');
});

test('facade get() throws for an unknown workflow', function () {
    Laraflow::get('missing');
})->throws(RuntimeException::class);

test('facade all() returns every registered workflow', function () {
    $all = Laraflow::all();
    expect($all)->toHaveKey('order');
    expect($all['order'])->toBeInstanceOf(Workflow::class);
});

test('facade-resolved workflow can apply transitions', function () {
    $order = new stdClass();
    $order->status = 'draft';

    Laraflow::get('order')->apply($order, 'submit');

    expect($order->status)->toBe('submitted');
});

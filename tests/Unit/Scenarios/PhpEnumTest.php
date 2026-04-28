<?php

declare(strict_types=1);

use Laraflow\Engine\Validator;
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Import\YamlImporter;

beforeEach(function () {
    $yaml = file_get_contents(__DIR__ . '/../../Fixtures/php-enum.yaml');
    $result = YamlImporter::import($yaml);
    $this->definition = $result['definition'];
    $this->meta = $result['meta'];
});

test('resolves enum place names to short names', function () {
    $names = array_map(fn ($p) => $p->name, $this->definition->places);
    expect($names)->toBe(['New', 'Processing', 'Shipped', 'Delivered', 'Cancelled']);
});

test('resolves enum transition names to short names', function () {
    $names = array_map(fn ($t) => $t->name, $this->definition->transitions);
    expect($names)->toBe(['Process', 'Ship', 'Deliver', 'Cancel']);
});

test('resolves initial_marking from enum value', function () {
    expect($this->definition->initialMarking)->toBe(['New']);
});

test('resolves enum values in from/to arrays', function () {
    $ship = collect($this->definition->transitions)->firstWhere('name', 'Ship');
    expect($ship->froms)->toBe(['Processing']);
    expect($ship->tos)->toBe(['Shipped']);
});

test('preserves metadata on enum-keyed places', function () {
    $processing = collect($this->definition->places)->firstWhere('name', 'Processing');
    expect($processing->metadata)->toBe(['bg_color' => 'ORANGE']);

    $cancelled = collect($this->definition->places)->firstWhere('name', 'Cancelled');
    expect($cancelled->metadata)->toBe(['bg_color' => 'Red']);
});

test('produces a valid definition', function () {
    $result = Validator::validate($this->definition);
    expect($result->valid)->toBeTrue();
});

test('runs the full engine flow', function () {
    $engine = new WorkflowEngine($this->definition);
    expect($engine->getActivePlaces())->toBe(['New']);

    $engine->apply('Process');
    expect($engine->getActivePlaces())->toBe(['Processing']);

    $engine->apply('Ship');
    expect($engine->getActivePlaces())->toBe(['Shipped']);

    $engine->apply('Deliver');
    expect($engine->getActivePlaces())->toBe(['Delivered']);
});

test('supports cancel from New', function () {
    $engine = new WorkflowEngine($this->definition);
    expect($engine->can('Cancel')->allowed)->toBeTrue();

    $engine->apply('Cancel');
    expect($engine->getActivePlaces())->toBe(['Cancelled']);
    expect($engine->getEnabledTransitions())->toBe([]);
});

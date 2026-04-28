<?php

declare(strict_types=1);

use Laraflow\Engine\Analyzer;
use Laraflow\Engine\Validator;
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Enums\PlacePattern;
use Laraflow\Import\YamlImporter;

beforeEach(function () {
    $yaml = file_get_contents(__DIR__ . '/../../Fixtures/blog-event.yaml');
    $result = YamlImporter::import($yaml);
    $this->definition = $result['definition'];
    $this->meta = $result['meta'];
});

// --- !php/const resolution ---

test('resolves place names from !php/const to short names', function () {
    $names = array_map(fn ($p) => $p->name, $this->definition->places);
    expect($names)->toBe([
        'NEW_BLOG',
        'CHECKING_CONTENT',
        'NEED_REVIEW',
        'NEED_UPDATE',
        'PUBLISHED',
    ]);
});

test('resolves transition names from !php/const to short names', function () {
    $names = array_map(fn ($t) => $t->name, $this->definition->transitions);
    expect($names)->toBe([
        'CREATE_BLOG',
        'VALID',
        'INVALID',
        'PUBLISH',
        'NEED_REVIEW',
        'REJECT',
        'UPDATE',
    ]);
});

// --- Structure ---

test('is type state_machine with 5 places and 7 transitions', function () {
    expect($this->definition->type->value)->toBe('state_machine');
    expect($this->definition->places)->toHaveCount(5);
    expect($this->definition->transitions)->toHaveCount(7);
});

test('has initial_marking [NEW_BLOG]', function () {
    expect($this->definition->initialMarking)->toBe(['NEW_BLOG']);
});

test('CHECKING_CONTENT has bg_color ORANGE', function () {
    $place = collect($this->definition->places)->firstWhere('name', 'CHECKING_CONTENT');
    expect($place->metadata)->toBe(['bg_color' => 'ORANGE']);
});

test('PUBLISHED has bg_color Lime', function () {
    $place = collect($this->definition->places)->firstWhere('name', 'PUBLISHED');
    expect($place->metadata)->toBe(['bg_color' => 'Lime']);
});

test('NEED_UPDATE has bg_color Orchid', function () {
    $place = collect($this->definition->places)->firstWhere('name', 'NEED_UPDATE');
    expect($place->metadata)->toBe(['bg_color' => 'Orchid']);
});

test('marking_store is method with property state', function () {
    expect($this->meta->markingStore->value)->toBe('method');
    expect($this->meta->property)->toBe('state');
});

// --- Engine flow ---

test('happy path: NEW_BLOG -> CREATE_BLOG -> VALID -> PUBLISH', function () {
    $engine = new WorkflowEngine($this->definition);
    expect($engine->getActivePlaces())->toBe(['NEW_BLOG']);

    $engine->apply('CREATE_BLOG');
    expect($engine->getActivePlaces())->toBe(['CHECKING_CONTENT']);

    $engine->apply('VALID');
    expect($engine->getActivePlaces())->toBe(['NEED_REVIEW']);

    $engine->apply('PUBLISH');
    expect($engine->getActivePlaces())->toBe(['PUBLISHED']);
});

test('rejection path: CHECKING_CONTENT -> INVALID -> NEED_UPDATE -> UPDATE -> NEED_REVIEW', function () {
    $engine = new WorkflowEngine($this->definition);
    $engine->apply('CREATE_BLOG');

    $engine->apply('INVALID');
    expect($engine->getActivePlaces())->toBe(['NEED_UPDATE']);

    $engine->apply('UPDATE');
    expect($engine->getActivePlaces())->toBe(['NEED_REVIEW']);
});

test('PUBLISHED can transition back to NEED_REVIEW (un-publish loop)', function () {
    $engine = new WorkflowEngine($this->definition);
    $engine->apply('CREATE_BLOG');
    $engine->apply('VALID');
    $engine->apply('PUBLISH');
    expect($engine->getActivePlaces())->toBe(['PUBLISHED']);

    $engine->apply('NEED_REVIEW');
    expect($engine->getActivePlaces())->toBe(['NEED_REVIEW']);
});

test('from CHECKING_CONTENT, both VALID and INVALID are enabled (xor-split)', function () {
    $engine = new WorkflowEngine($this->definition);
    $engine->apply('CREATE_BLOG');

    $enabled = array_map(fn ($t) => $t->name, $engine->getEnabledTransitions());
    sort($enabled);
    expect($enabled)->toBe(['INVALID', 'VALID']);
});

test('from NEW_BLOG, only CREATE_BLOG is enabled', function () {
    $engine = new WorkflowEngine($this->definition);
    $enabled = array_map(fn ($t) => $t->name, $engine->getEnabledTransitions());
    expect($enabled)->toBe(['CREATE_BLOG']);
});

// --- Validator ---

test('blog-event definition validates as valid', function () {
    $result = Validator::validate($this->definition);
    expect($result->valid)->toBeTrue();
    expect($result->errors)->toBe([]);
});

// --- Analyzer ---

test('CHECKING_CONTENT has xor-split pattern (state machine choice point)', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->places['CHECKING_CONTENT']->patterns)->toContain(PlacePattern::XorSplit);
});

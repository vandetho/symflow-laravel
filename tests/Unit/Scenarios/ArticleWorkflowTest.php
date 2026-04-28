<?php

declare(strict_types=1);

use Laraflow\Engine\Analyzer;
use Laraflow\Engine\Validator;
use Laraflow\Engine\WorkflowEngine;
use Laraflow\Enums\PlacePattern;
use Laraflow\Enums\TransitionPattern;
use Laraflow\Export\YamlExporter;
use Laraflow\Import\YamlImporter;

beforeEach(function () {
    $yaml = file_get_contents(__DIR__ . '/../../Fixtures/article-workflow.yaml');
    $result = YamlImporter::import($yaml);
    $this->definition = $result['definition'];
    $this->meta = $result['meta'];
});

// --- Import ---

test('article-workflow has correct name', function () {
    expect($this->definition->name)->toBe('article_workflow');
});

test('article-workflow has type "workflow"', function () {
    expect($this->definition->type->value)->toBe('workflow');
});

test('article-workflow has 6 places', function () {
    expect($this->definition->places)->toHaveCount(6);
});

test('article-workflow has 4 transitions', function () {
    expect($this->definition->transitions)->toHaveCount(4);
});

test('article-workflow has correct initial marking', function () {
    expect($this->definition->initialMarking)->toBe(['NEW_ARTICLE']);
});

test('article-workflow preserves CHECKING_CONTENT bg_color metadata', function () {
    $place = collect($this->definition->places)->firstWhere('name', 'CHECKING_CONTENT');
    expect($place->metadata)->toBe(['bg_color' => 'ORANGE']);
});

test('article-workflow preserves PUBLISHED bg_color metadata', function () {
    $place = collect($this->definition->places)->firstWhere('name', 'PUBLISHED');
    expect($place->metadata)->toBe(['bg_color' => 'Lime']);
});

// --- Transition structure ---

test('CREATE_ARTICLE is an AND-split (1 from, 2 to)', function () {
    $t = collect($this->definition->transitions)->firstWhere('name', 'CREATE_ARTICLE');
    expect($t)->not->toBeNull();
    expect($t->froms)->toBe(['NEW_ARTICLE']);
    expect($t->tos)->toBe(['CHECKING_CONTENT', 'CHECKING_SPELLING']);
});

test('PUBLISH is an AND-join (2 from, 1 to)', function () {
    $t = collect($this->definition->transitions)->firstWhere('name', 'PUBLISH');
    expect($t)->not->toBeNull();
    expect($t->froms)->toBe(['CONTENT_APPROVED', 'SPELLING_APPROVED']);
    expect($t->tos)->toBe(['PUBLISHED']);
});

// --- Engine flow ---

test('full flow: CREATE_ARTICLE -> APPROVE_CONTENT -> APPROVE_SPELLING -> PUBLISH', function () {
    $engine = new WorkflowEngine($this->definition);
    expect($engine->getActivePlaces())->toBe(['NEW_ARTICLE']);

    $engine->apply('CREATE_ARTICLE');
    $places = $engine->getActivePlaces();
    sort($places);
    expect($places)->toBe(['CHECKING_CONTENT', 'CHECKING_SPELLING']);

    $engine->apply('APPROVE_CONTENT');
    $places = $engine->getActivePlaces();
    sort($places);
    expect($places)->toBe(['CHECKING_SPELLING', 'CONTENT_APPROVED']);

    $engine->apply('APPROVE_SPELLING');
    $places = $engine->getActivePlaces();
    sort($places);
    expect($places)->toBe(['CONTENT_APPROVED', 'SPELLING_APPROVED']);

    $engine->apply('PUBLISH');
    expect($engine->getActivePlaces())->toBe(['PUBLISHED']);
});

test('PUBLISH requires both approvals (AND-join semantics)', function () {
    $engine = new WorkflowEngine($this->definition);
    $engine->apply('CREATE_ARTICLE');

    $engine->apply('APPROVE_CONTENT');
    $result = $engine->can('PUBLISH');
    expect($result->allowed)->toBeFalse();
    expect($result->blockers[0]->code)->toBe('not_in_place');

    $engine->apply('APPROVE_SPELLING');
    expect($engine->can('PUBLISH')->allowed)->toBeTrue();
});

test('PUBLISH cannot fire with only spelling approved', function () {
    $engine = new WorkflowEngine($this->definition);
    $engine->apply('CREATE_ARTICLE');
    $engine->apply('APPROVE_SPELLING');
    expect($engine->can('PUBLISH')->allowed)->toBeFalse();
});

// --- Round-trip ---

test('YAML round-trip preserves structure and metadata', function () {
    $exported = YamlExporter::export($this->definition, $this->meta);
    $reimported = YamlImporter::import($exported)['definition'];

    expect($reimported->name)->toBe($this->definition->name);
    expect($reimported->type)->toBe($this->definition->type);
    expect($reimported->initialMarking)->toBe($this->definition->initialMarking);
    expect(array_map(fn ($p) => $p->name, $reimported->places))
        ->toBe(array_map(fn ($p) => $p->name, $this->definition->places));
    expect($reimported->transitions)->toHaveCount(count($this->definition->transitions));

    foreach ($this->definition->transitions as $t) {
        $rt = collect($reimported->transitions)->firstWhere('name', $t->name);
        expect($rt)->not->toBeNull();
        expect($rt->froms)->toBe($t->froms);
        expect($rt->tos)->toBe($t->tos);
    }

    foreach ($this->definition->places as $place) {
        if ($place->metadata === null) {
            continue;
        }
        $rp = collect($reimported->places)->firstWhere('name', $place->name);
        expect($rp->metadata)->toBe($place->metadata);
    }
});

// --- Analyzer ---

test('analyzer detects AND-split on CREATE_ARTICLE', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->transitions['CREATE_ARTICLE']->pattern)->toBe(TransitionPattern::AndSplit);
});

test('analyzer detects AND-join on PUBLISH', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->transitions['PUBLISH']->pattern)->toBe(TransitionPattern::AndJoin);
});

test('analyzer detects simple transitions', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->transitions['APPROVE_SPELLING']->pattern)->toBe(TransitionPattern::Simple);
    expect($analysis->transitions['APPROVE_CONTENT']->pattern)->toBe(TransitionPattern::Simple);
});

test('analyzer detects and-split pattern on parallel places', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->places['CHECKING_CONTENT']->patterns)->toContain(PlacePattern::AndSplit);
    expect($analysis->places['CHECKING_SPELLING']->patterns)->toContain(PlacePattern::AndSplit);
});

test('analyzer detects and-join pattern on convergence places', function () {
    $analysis = Analyzer::analyze($this->definition);
    expect($analysis->places['CONTENT_APPROVED']->patterns)->toContain(PlacePattern::AndJoin);
    expect($analysis->places['SPELLING_APPROVED']->patterns)->toContain(PlacePattern::AndJoin);
});

// --- Validator ---

test('article-workflow validates as valid', function () {
    $result = Validator::validate($this->definition);
    expect($result->valid)->toBeTrue();
    expect($result->errors)->toBe([]);
});

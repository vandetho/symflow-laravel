<?php

declare(strict_types=1);

use Laraflow\Data\WorkflowMeta;
use Laraflow\Enums\MarkingStoreType;
use Laraflow\Enums\WorkflowType;
use Laraflow\Export\SvgExporter;
use Laraflow\Tests\Fixtures\Definitions;

function orderMeta(): WorkflowMeta
{
    return new WorkflowMeta(
        name: 'order',
        type: WorkflowType::StateMachine,
        markingStore: MarkingStoreType::Method,
        initialMarking: ['draft'],
        supports: 'App\\Entity\\Order',
        property: 'currentState',
    );
}

function articleMeta(): WorkflowMeta
{
    return new WorkflowMeta(
        name: 'article_review',
        type: WorkflowType::Workflow,
        markingStore: MarkingStoreType::Method,
        initialMarking: ['draft'],
        supports: 'App\\Entity\\Article',
        property: 'marking',
    );
}

test('produces a self-contained SVG with viewBox', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());
    expect(str_starts_with($svg, '<svg '))->toBeTrue();
    expect($svg)->toMatch('/viewBox="[\d. -]+"/');
    expect(str_ends_with($svg, '</svg>'))->toBeTrue();
});

test('includes the workflow name as title', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());
    expect($svg)->toContain('<title>order</title>');
});

test('renders every place as text', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());

    foreach (Definitions::orderStateMachine()->places as $p) {
        expect($svg)->toContain(">{$p->name}</text>");
    }
});

test('renders every transition name as text', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());

    foreach (Definitions::orderStateMachine()->transitions as $t) {
        expect($svg)->toContain($t->name);
    }
});

test('includes guard expressions in transition labels', function () {
    $svg = SvgExporter::export(Definitions::guardedStateMachine(), orderMeta());
    expect($svg)->toContain('subject.amount &lt; 1000');
});

test('renders AND-split with multiple targets', function () {
    $svg = SvgExporter::export(Definitions::articleReviewWorkflow(), articleMeta());
    expect($svg)->toContain('checking_content');
    expect($svg)->toContain('checking_spelling');
    expect($svg)->toContain('start_review');
});

test('defaults to dark theme background', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());
    expect($svg)->toContain('#0a0a14');
});

test('supports light theme override', function () {
    $svg = SvgExporter::export(Definitions::orderStateMachine(), orderMeta(), 'light');
    expect($svg)->toMatch('/<rect[^>]*fill="#ffffff"/');

    $dark = SvgExporter::export(Definitions::orderStateMachine(), orderMeta());
    expect($dark)->toMatch('/<rect[^>]*fill="#0a0a14"/');
});

test('escapes XML special characters in labels', function () {
    $svg = SvgExporter::export(Definitions::guardedStateMachine(), orderMeta());
    expect($svg)->not->toMatch('/<text[^>]*>[^<]*[<>][^<]*<\/text>/');
});

test('returns a placeholder when no nodes are present', function () {
    $svg = SvgExporter::renderPositioned(nodes: [], edges: []);
    expect($svg)->toContain('Empty workflow');
});

test('renders weighted transition labels', function () {
    $svg = SvgExporter::export(Definitions::weightedWorkflow(), new WorkflowMeta(name: 'factory'));
    expect($svg)->toContain('manufacture (3:2)');
});

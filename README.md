# SymFlow for Laravel

[![CI](https://github.com/vandetho/symflow/actions/workflows/ci.yaml/badge.svg)](https://github.com/vandetho/symflow/actions/workflows/ci.yaml)
[![Total Downloads](https://img.shields.io/packagist/dt/vandetho/symflow-laravel.svg)](https://packagist.org/packages/vandetho/symflow-laravel)
[![Monthly Downloads](https://img.shields.io/packagist/dm/vandetho/symflow-laravel.svg)](https://packagist.org/packages/vandetho/symflow-laravel)
[![Latest Version](https://img.shields.io/packagist/v/vandetho/symflow-laravel.svg)](https://packagist.org/packages/vandetho/symflow-laravel)
[![License](https://img.shields.io/packagist/l/vandetho/symflow-laravel.svg)](https://packagist.org/packages/vandetho/symflow-laravel)

A Symfony-compatible workflow engine for Laravel. State machines, Petri nets, guards, events, weighted arcs, middleware, and YAML/JSON/PHP import/export.

Part of the [SymFlow](https://github.com/vandetho/symflow) ecosystem. See also [symflow](https://www.npmjs.com/package/symflow) for the TypeScript/Node.js version.

## Installation

```bash
composer require vandetho/symflow-laravel
php artisan vendor:publish --tag=laraflow-config
```

## Quick Start

Define a workflow in `config/laraflow.php`:

```php
'workflows' => [
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
],
```

Use the Eloquent trait:

```php
use Laraflow\Eloquent\HasWorkflowTrait;

class Order extends Model
{
    use HasWorkflowTrait;

    protected function getDefaultWorkflowName(): string
    {
        return 'order';
    }
}

$order->applyTransition('submit');
$order->canTransition('approve'); // true/false
```

Or use the Facade:

```php
use Laraflow\Facades\Laraflow;

$workflow = Laraflow::get('order');
$workflow->apply($order, 'submit');
```

## Features

- **Two workflow types** -- `state_machine` and `workflow` (Petri net with parallel states)
- **Symfony event order** -- `guard > leave > transition > enter > entered > completed > announce`
- **Subject-driven API** -- mirrors Symfony's `$workflow->apply($entity, 'submit')` pattern
- **Marking stores** -- `property` and `method` stores, or implement your own
- **Pluggable guards** -- `GuardEvaluatorInterface` for custom authorization
- **Weighted arcs** -- `consumeWeight` / `produceWeight` for multi-token transitions
- **Middleware** -- wrap `apply()` with logging, transactions, metrics
- **Validation** -- 8 error types including BFS reachability analysis
- **Pattern analysis** -- AND-split, AND-join, OR-split, XOR detection
- **Import/Export** -- YAML (Symfony-compatible), JSON, PHP codegen, Mermaid, Graphviz DOT
- **Eloquent trait** -- `HasWorkflowTrait` for model integration
- **Laravel events** -- 7 event classes for the full transition lifecycle
- **Artisan commands** -- `laraflow:validate`, `laraflow:mermaid`, `laraflow:dot`

## Documentation

| Guide | Description |
|-------|-------------|
| [Getting Started](./docs/getting-started.md) | Installation, first workflow, Eloquent trait |
| [Engine API](./docs/engine-api.md) | WorkflowEngine, guards, validation, pattern analysis |
| [Subject API](./docs/subject-api.md) | Workflow facade, marking stores, config-driven workflows |
| [Weighted Arcs](./docs/weighted-arcs.md) | Multi-token transitions |
| [Middleware](./docs/middleware.md) | Lifecycle hooks, transactions, logging |
| [Events](./docs/events.md) | Symfony event order, Laravel event integration |
| [Artisan Commands](./docs/artisan-commands.md) | validate, mermaid, dot |
| [Persistence Formats](./docs/persistence-formats.md) | YAML, JSON, PHP, Mermaid, Graphviz |

## License

MIT

# CLAUDE.md -- symflow-laravel

## Project Overview

Symfony-compatible workflow engine for Laravel. State machines, Petri nets, guards, events, validation, weighted arcs, middleware, and multi-format import/export.

- **Packagist:** https://packagist.org/packages/vandetho/symflow-laravel
- **Repo:** https://github.com/vandetho/symflow-laravel
- **Sibling:** [symflow](https://www.npmjs.com/package/symflow) (TypeScript/Node.js version, separate repo)
- **Stack:** PHP 8.2+ / Laravel 11+ / Pest (test) / symfony/yaml
- **Namespace:** `Laraflow\`

---

## Coding Conventions

### Style

- **PHP version:** 8.2+ with `declare(strict_types=1)`
- **Autoloading:** PSR-4 under `Laraflow\` namespace
- **Classes:** `readonly` where applicable, named constructor arguments
- **Enums:** PHP 8.2 string-backed enums
- **Tests:** Pest with `test()` / `expect()` syntax

### Naming

- **Files/Classes:** PascalCase (`WorkflowEngine.php`, `PropertyMarkingStore.php`)
- **Methods:** camelCase (`getActivePlaces`, `canTransition`)
- **Enums:** PascalCase cases (`WorkflowType::StateMachine`)
- **Config keys:** snake_case (`marking_store`, `initial_marking`)

---

## Architecture

### Engine (`src/Engine/`)

- `WorkflowEngine` -- core class, manages markings, fires transitions, emits events
- Two workflow types: `StateMachine` (single active place) and `Workflow` (Petri net)
- Event order mirrors Symfony: guard > leave > transition > enter > entered > completed > announce
- Weighted arcs: `Transition->consumeWeight` / `produceWeight` (optional, default 1)
- Middleware: `use($mw)` or constructor `middleware` param -- wraps `apply()`, not `can()`
- `Validator::validate()` -- 8 error types + BFS reachability analysis
- `Analyzer::analyze()` -- pattern detection (AND-split, AND-join, OR-split, XOR)

### Subject (`src/Subject/`)

- `Workflow` -- subject-driven facade wrapping engine, reads/writes marking via `MarkingStoreInterface`
- `PropertyMarkingStore` -- reads/writes a model property (string or array)
- `MethodMarkingStore` -- calls getter/setter methods on subject
- Subject middleware with `SubjectMiddlewareContext` (includes `$subject`)

### Import/Export

- `YamlImporter` -- Symfony-compatible, handles `!php/const` and `!php/enum` tags
- `JsonImporter` -- `{ definition, meta }` shape
- `FileLoader` -- dispatches by extension (.yaml, .json, .php)
- `YamlExporter` -- produces Symfony `framework.workflows` config
- `JsonExporter`, `PhpExporter`, `MermaidExporter`, `GraphvizExporter`, `SvgExporter` (auto-layout, dark/light theme)

### Laravel Integration

- `LaraflowServiceProvider` -- registers `WorkflowRegistryInterface` singleton, publishes config
- `Laraflow` facade -- resolves `WorkflowRegistryInterface`
- `WorkflowRegistry` -- builds workflows from `config/laraflow.php`
- `HasWorkflowTrait` -- Eloquent model trait: `canTransition()`, `applyTransition()`, etc.
- 7 Laravel event classes (one per `WorkflowEventType`) for event subscribers
- Artisan commands: `laraflow:validate`, `laraflow:mermaid`, `laraflow:dot`

### Data Classes (`src/Data/`)

All readonly value objects: `Place`, `Transition`, `WorkflowDefinition`, `WorkflowMeta`, `Marking` (with `ArrayAccess`), `TransitionResult`, `TransitionBlocker`, `WorkflowEvent`, `SubjectEvent`, `ValidationResult`, `ValidationError`, `MiddlewareContext`, `SubjectMiddlewareContext`, `PlaceAnalysis`, `TransitionAnalysis`, `WorkflowAnalysis`

### Enums (`src/Enums/`)

`WorkflowType`, `WorkflowEventType`, `ValidationErrorType`, `TransitionPattern`, `PlacePattern`, `MarkingStoreType`

### Contracts (`src/Contracts/`)

`MarkingStoreInterface`, `GuardEvaluatorInterface`, `WorkflowRegistryInterface`

---

## Build & Test

```bash
composer install
./vendor/bin/pest              # 187 tests, 339 assertions
```

### Tests

- Pest with `test()` / `expect()`
- Fixtures in `tests/Fixtures/Definitions.php` and `tests/Fixtures/*.yaml`
- Unit tests: Engine, Validator, Analyzer, Subject, Import, Export, Scenarios (article-workflow, blog-event, php-enum)
- Feature tests: ServiceProvider, Facade, Artisan commands
- 187 tests across 21 test files

---

## Release Process

- **Conventional commits** (`feat:` -> minor, `fix:` -> patch, `chore:`/`docs:` -> hidden, `test:`/`ci:`/`refactor:` -> hidden)
- **release-please** automates version bumps and `CHANGELOG.md` (config: `release-please-config.json`, manifest: `.release-please-manifest.json`, release-type: `simple`)
- **CI** (`ci.yaml`): matrix tests on push/PR to `main` (PHP 8.2/8.3/8.4 x Laravel 11/12)
- **Release** (`release-please.yaml`): on push to `main`, opens/updates a Release PR; merging it creates the `vX.Y.Z` tag and GitHub release with auto-generated notes
- **Packagist** auto-syncs from GitHub via webhook (configured on packagist.org, not in this repo)

---

## Key Constraints

- PHP 8.2+ strict types
- Laravel 11+ / 12+ compatibility
- Only runtime dep: `symfony/yaml`
- Marking is `array<string, int>` wrapped in `Marking` class
- Engine returns cloned markings (immutable read)
- YAML export produces valid Symfony `framework.workflows` config
- Event order matches Symfony exactly
- Middleware wraps `apply()` only, not `can()`

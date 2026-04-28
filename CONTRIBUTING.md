# Contributing to SymFlow for Laravel

Thanks for your interest in contributing! This guide will help you get started.

## Development Setup

```bash
git clone https://github.com/vandetho/symflow-laravel.git
cd symflow-laravel
composer install
```

## Commands

```bash
./vendor/bin/pest              # Run the test suite (Pest)
./vendor/bin/pest --filter=... # Run a specific test
```

## Making Changes

1. Fork the repo and create a branch from `main`.
2. Make your changes.
3. Add or update tests for any new or changed behavior.
4. Ensure the test suite passes: `./vendor/bin/pest`.
5. Submit a pull request.

## Code Style

- PHP 8.2+ with `declare(strict_types=1);` at the top of every source file.
- PSR-4 autoloading under the `Laraflow\` namespace.
- 4-space indentation.
- Files and classes in PascalCase (`WorkflowEngine.php`); methods in camelCase; enum cases in PascalCase; config keys in snake_case.
- Prefer `final readonly` value objects in `src/Data/` and named constructor arguments at call sites.
- Use string-backed enums (PHP 8.2+) rather than class constants for fixed sets.
- Tests use the Pest `test()` / `expect()` syntax.

## Architecture Notes

- Engine code lives in `src/Engine/`; subject-driven facade in `src/Subject/`.
- All importers/exporters live in `src/Import/` and `src/Export/` respectively, each as a single class with static `import()` / `export()` methods.
- Laravel integration (service provider, facade, registry, Eloquent trait, Artisan commands) lives in `src/LaraflowServiceProvider.php`, `src/Facades/`, `src/Registry/`, `src/Eloquent/`, and `src/Console/`.
- Event order must match Symfony's: `guard > leave > transition > enter > entered > completed > announce`.
- Middleware wraps `apply()` only, not `can()`.
- The marking is `array<string, int>` (token counts) wrapped in the `Marking` value object.

See [CLAUDE.md](CLAUDE.md) for a more detailed architecture overview.

## Adding a New Export Format

Follow the existing pattern (`YamlExporter`, `JsonExporter`, `MermaidExporter`, `GraphvizExporter`, `SvgExporter`):

1. Create `src/Export/{Format}Exporter.php` with a static `export()` method that takes `WorkflowDefinition` (and `WorkflowMeta` if needed).
2. Add tests in `tests/Unit/Export/{Format}ExporterTest.php` using fixtures from `tests/Fixtures/Definitions.php`.
3. If the format is round-trippable, also add a matching `src/Import/{Format}Importer.php` with tests.
4. Update the README and CLAUDE.md to mention the new format.

## Commit Messages

This project uses [Conventional Commits](https://www.conventionalcommits.org/) — release-please reads them to compute the next version automatically:

- `feat:` — new feature (bumps minor)
- `fix:` — bug fix (bumps patch)
- `perf:` — performance improvement (bumps patch)
- `docs:` — documentation only (no release)
- `chore:` / `ci:` / `test:` / `refactor:` — maintenance (no release, hidden from changelog)

Breaking changes go in the commit body or footer with `BREAKING CHANGE:` and bump the major version.

## Reporting Bugs

Open an issue at [github.com/vandetho/symflow-laravel/issues](https://github.com/vandetho/symflow-laravel/issues) using the bug-report template. Include the PHP and Laravel versions you're on, a minimal reproduction, expected behavior, and actual behavior.

## Security Issues

Please **do not** open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md) for how to report them privately.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`vusys/laravel-query-ricer-extreme` — a Laravel package. Targets **PHP 8.3+** and **Laravel 11 / 12 / 13**.
Library code only — no application code. Pre-1.0, so backwards-compat breaks are acceptable when called out.

<!-- Replace this section with actual package description once the concept is decided. -->

## Commands

```bash
composer test            # PHPUnit, default suite (Package) — excludes Performance + fuzzer group
composer analyse         # PHPStan / Larastan level 9, NO baseline allowed
composer pint:check      # Laravel Pint, --test mode (style check)
composer rector:check    # Rector --dry-run
composer fuzz            # opt-in seeded fuzzers (PHPUnit --group fuzzer)
composer test:coverage   # XDEBUG_MODE=coverage phpunit --coverage-text
```

Run a single test:

```bash
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit tests/Feature/ExampleTest.php
vendor/bin/phpunit --testsuite Performance    # benchmarks (opt-in)
```

Backend matrix — set `DB_CONNECTION` to one of `sqlite` (default), `mysql`, `mariadb`, `pgsql`. CI runs every PHP × Laravel × DB cell (24 total).

## Code conventions

- **`declare(strict_types=1);`** in every file.
- **PHPStan level 9, no baseline, no `@phpstan-ignore`.** If you need to silence one, fix the type instead.
- **No code comments unless WHY is non-obvious.**
- Laravel Pint enforces style. Run `composer pint` to fix.

## Tests

PHPUnit 12 with Orchestra Testbench.

### Test categories
- `tests/Unit/` — pure-PHP unit tests (no DB). Extend `PHPUnit\Framework\TestCase` directly — do **not** boot Laravel.
- `tests/Feature/` — DB-backed integration tests. Extend `Vusys\QueryRicerExtreme\Tests\TestCase`.
- `tests/Performance/` — separate suite (`vendor/bin/phpunit --testsuite Performance`). Not run on PR CI.

## Before every push

Run all four checks locally before committing or pushing. CI runs the same commands and a failure there is just noise that could have been caught here.

```bash
composer test        # must be green
composer analyse     # must report no errors
composer pint:check  # must pass (run composer pint to auto-fix, then re-check)
composer rector:check # must report no changes (run composer rector to auto-fix, then re-check)
```

If any check fails, fix it before pushing. Do not bypass or skip checks.

## Things to avoid

- Adding `@phpstan-ignore` comments or PHPStan baseline entries — explicitly disallowed.
- Adding code comments explaining WHAT — only add when WHY is non-obvious.

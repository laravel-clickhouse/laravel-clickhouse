# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel ClickHouse integration package that provides:
- Laravel Database Connection for ClickHouse
- Eloquent Model support for ClickHouse
- Query Builder with ClickHouse-specific features
- Schema Builder for ClickHouse DDL operations
- Parallel query execution via Guzzle async HTTP pool

## Development Commands

### Testing
- `composer test` - Run PHPUnit tests
- `vendor/bin/phpunit --filter TestName` - Run specific test
- Tests require ClickHouse server running on localhost:8123 (see phpunit.xml.dist)
- **Local runs use `phpunit.xml`** (gitignored, copied from `phpunit.xml.dist`) to override env vars like `CLICKHOUSE_HOST` for the local environment. PHPUnit auto-loads `phpunit.xml` when present, so existing commands (`composer test`, `vendor/bin/phpunit ...`) just work — no extra `--configuration` flag needed.

### Code Quality
- `composer phpstan` - Run static analysis with PHPStan (level 9): core + laravel configs
- `composer phpstan:core` / `phpstan:laravel` / `phpstan:hypervel` - Run one config (`phpstan.neon` covers framework-free code only — the core traits are analysed per-bridge; the laravel/hypervel configs analyse the core traits in each bridge's context; `phpstan:hypervel` needs hypervel/components installed)
- `composer cs` - Check code style with Laravel Pint
- `composer cs:fix` - Fix code style issues with Laravel Pint

### Documentation
- `cd docs && bun run dev` - Start VitePress dev server
- `cd docs && bun run build` - Build documentation site

## Architecture Overview

### Core Components

**Client Layer** (`src/Core/Client/`)
- `Client.php` - Main ClickHouse client with connection management
- `Statement.php` - Prepared statement handling
- `Response.php` - Response parsing and data handling
- `TransportFactory.php` - HTTP transport factory
- `Contracts/Transport.php` - Transport interface
- `Transports/Guzzle.php` - Guzzle HTTP transport (default, supports parallel)
- `Transports/Curl.php` - cURL-based transport via phpclickhouse

**Laravel Integration** (`src/Laravel/`)
- `ClickHouseServiceProvider.php` - Laravel service provider registration
- `Connection.php` - Laravel Database Connection extending BaseConnection
- `Parallel.php` - Parallel query and statement execution support

**Query Layer** (`src/Laravel/Query/`)
- `Builder.php` - ClickHouse-specific query builder extending Laravel's builder
- `Grammar.php` - SQL grammar with ClickHouse syntax support

**Eloquent Layer** (`src/Laravel/Eloquent/`)
- `Model.php` - ClickHouse Eloquent model (non-incrementing IDs by default)
- `Builder.php` - Eloquent builder with delete/forceDelete partition support

**Schema Layer** (`src/Laravel/Schema/`)
- `Builder.php` - ClickHouse Schema builder with Blueprint integration
- `Grammar.php` - Schema grammar for CREATE/ALTER/DROP with ClickHouse extensions
- `Blueprint.php` - Extended Blueprint with ClickHouse-specific methods
- `ColumnDefinition.php` - Column definition with lowCardinality() support
- `CommandDefinition.php` - Command definition with sync() support
- `IndexDefinition.php` - Index definition with granularity() support

**Migrations** (`src/Laravel/Migrations/`)
- `DatabaseMigrationRepository.php` - ClickHouse-compatible migration repository

**Testing Traits** (`src/Laravel/Testing/`, `src/Hypervel/Testing/`)
- `RefreshDatabase.php` / `DatabaseMigrations.php` / `DatabaseTruncation.php` - ClickHouse-aware wrappers over each framework's testing traits; shared `db:wipe` pre-pass logic lives in `src/Core/Testing/WipesClickHouseConnections.php`

**Enums** (`src/Core/Enums/`)
- `Format.php` - ClickHouse input format options

**Support** (`src/Core/Support/`)
- `DateTimeFormatter.php` - Shared DateTime formatting
- `Escaper.php` - Value escaping and SQL injection prevention
- `JsonEachRowEncoder.php` - JSONEachRow payload encoding

**Exceptions** (`src/Core/Exceptions/`)
- `QueryException.php` - Query execution exception
- `ParallelQueryException.php` - Parallel query exception with partial results

## Configuration

ClickHouse connection config in Laravel `config/database.php`:
```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
    'port' => env('CLICKHOUSE_PORT', 8123),
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'transport' => env('CLICKHOUSE_TRANSPORT', 'guzzle'),
    'engine' => env('CLICKHOUSE_ENGINE'),
    'use_lightweight_delete' => env('CLICKHOUSE_USE_LIGHTWEIGHT_DELETE', false),
]
```

## Testing Environment

Tests expect ClickHouse server running with:
- Host: 127.0.0.1:8123
- Database: default
- Username: default
- Password: default

## Namespace Structure

All classes use `ClickHouse\` as root namespace:
- `ClickHouse\Core\` - Framework-agnostic core: HTTP client (`Core\Client`), enums (`Core\Enums`), exceptions (`Core\Exceptions`), utilities (`Core\Support`), and the shared bridge traits/contracts (`Core\Query`, `Core\Schema`, `Core\Connection`, ...)
- `ClickHouse\Laravel\` - Laravel framework bridge
- `ClickHouse\Hypervel\` - Hypervel framework bridge

### Framework-Free Constraint Inside `src/Core`

`src/Core` splits into two tiers with different rules:

- **Standalone classes** (`Core/Client`, `Core/Enums`, `Core/Exceptions`,
  `Core/Support`): MUST be fully framework-free — no `Illuminate\*` /
  `Hypervel\*` imports, no framework helpers (`collect()`, `tap()`,
  `Arr::*`), analysable without any framework installed. These directories
  are listed in `phpstan.neon`'s `paths`; **when adding a new standalone
  directory under `src/Core`, add it to `phpstan.neon` too** — PHPStan skips
  traits with no in-scope using class, so a forgotten path means the code is
  silently unanalysed in the default config.
- **Bridge traits/contracts** (`Core/Query`, `Core/Schema`,
  `Core/Connection`, `Core/Eloquent`, `Core/Migrations`,
  `RunsParallelQueries`, ...): framework-free in the sense that they never
  `use` a framework class directly (framework types arrive via the using
  class's parent, class-string constants, or duck typing), but they only
  make sense mounted on a bridge class. Do NOT add them to `phpstan.neon` —
  they are analysed per-bridge by `phpstan.laravel.neon` and
  `phpstan.hypervel.neon`.

## Code Comments Language

- All code (`src/`, `tests/`) — comments and docblocks MUST be in English.
- Public docs (`docs/`, `README.md`) — follow the existing language of the file.

## Class Member Ordering

Within a class (or trait/interface), declare members in this order:

1. By kind: constants → properties → methods (constructor first among methods)
2. Within each kind, by visibility: public → protected → private

## Zero Duplication Between Bridges

The Laravel and Hypervel bridges must never duplicate logic — a single spot
of duplication is a future edit that silently misses one side. All shared
behaviour lives in `ClickHouse\Core\*`; a bridge class should contain only
what is genuinely framework-bound. Before adding anything to a bridge class,
try these techniques in order:

1. **Core trait with the full implementation** — the default. Method bodies,
   schema definitions, SQL compilation all belong in a trait
   (`BuildsClickHouseQueries`, `CompilesClickHouseSchema`, ...).
2. **Signature rule for trait methods overriding framework methods**: declare
   the *narrower* of the two parents' signatures. `: bool` / `: void` over an
   untyped Laravel parent is legal narrowing; an untyped trait method over a
   typed Hypervel parent is a fatal. Cast inside when the untyped parent may
   return a looser value (`(bool) parent::insert(...)`).
3. **Constructor injection for state** — a trait property cannot redeclare a
   parent property whose type differs between frameworks (untyped vs
   `array`). Assign in the trait's constructor instead (`$bindings`,
   `$selectComponents`, `$modifiers`), guarded with
   `method_exists(parent::class, '__construct')` when one framework's parent
   has no constructor.
4. **Class-string constants for framework classes** — when shared code must
   instantiate or `instanceof` a framework class, bind it via a bridge
   constant (`EXPRESSION`, `QUERY_EXCEPTION`, `ELOQUENT_BUILDER`, ...). Read
   the constant into a local before `instanceof` (PHP 8.2 compatibility).
5. **Marker interfaces in core** — for cross-framework type detection
   (`ClickHouseConnection`, `ClickHouseBuilder`) and for shared `@method`
   phpdoc (`ClickHouseColumnDefinition`); PHPStan resolves `@method` from
   implemented interfaces.

Acceptable per-bridge remainder (declarations, not logic): one-line
delegating overrides whose parent signatures differ irreconcilably, hook
implementations that `new` a framework class with framework-specific
mechanics, `@method`/`@extends` phpdoc referencing bridge classes, and
genuinely divergent mechanics (service-provider registration, PDO-vs-HTTP
connection plumbing).

## Testing Strategy for Bridges

Zero duplication applies to `src/`, NOT to `tests/`. Bridge test suites are
deliberately duplicated and must stay that way — they exist in two layers:

1. **Parity tests (mirrored per bridge)** — the same scenarios written once
   per bridge (`tests/Laravel/...` and `tests/Hypervel/...` mirror each
   other). A core trait mounted on an Illuminate parent and on a Hypervel
   parent is two different execution paths (different parent helpers,
   native types, constructors), so the mirror is exactly what verifies the
   zero-duplication architecture's risk. Never extract shared test code
   across bridges: a shared test would change both sides' expectations in
   one edit and let behavioural drift between frameworks go unnoticed.
   When adding a scenario to one bridge, add its mirror to the other in
   the same change — a missing mirror is a coverage gap, not a
   simplification.
2. **Framework-specific tests (no mirror, by design)** — scenarios that
   only exist on one framework get their own directories with no
   counterpart: for Hypervel, coroutine concurrency, pool
   lifecycle/reconnect, heartbeat and `Parallel`-under-Swoole (e.g.
   `tests/Hypervel/Feature/Coroutine/`); for Laravel, Orchestra-specific
   machinery (e.g. the in-memory PDO preservation workaround). Every
   behavioural claim in `docs/` about a framework-specific feature must be
   backed by a test in this layer.

Asymmetry between the suites is therefore only acceptable in layer 2, and
only when the scenario genuinely cannot occur on the other framework.

## Release Checklist

Before tagging a new release, run through every item below. Do not skip any step.

### 1. Code Quality Gates

All three must pass with zero errors:

```bash
composer cs        # Code style (Laravel Pint)
composer phpstan   # Static analysis (level 9)
composer test      # PHPUnit (requires ClickHouse on 127.0.0.1:8123)
```

### 2. File Integrity

- `composer.json` — verify metadata is correct (name, description, license, homepage, authors, keywords, autoload, Laravel auto-discovery)
- `LICENSE` — exists and is complete
- `README.md` — no broken links, no placeholder text, port numbers and paths are accurate
- `.gitignore` — no sensitive files leaking, no needed files excluded

### 3. Documentation Site

```bash
cd docs && bun run build
```

- Build must succeed with zero errors
- Verify `docs/index.md` hero links point to correct paths
- Verify `docs/.vitepress/config.ts` base path matches GitHub Pages URL (`/laravel-clickhouse/`)
- Spot-check cross-reference links between doc pages

### 4. GitHub Actions Workflows

- `.github/workflows/tests.yml` — CI config matches local test commands
- `.github/workflows/docs.yml` — docs deploy config is correct
- `.github/workflows/release.yml` — references `tests.yml` via `workflow_call`

### 5. Git State

- Working tree is clean (`git status` shows no uncommitted changes)
- No untracked files that should be committed
- All commits are pushed to remote

### 6. Tag and Release

```bash
git tag v<MAJOR>.<MINOR>.<PATCH>
git push origin v<MAJOR>.<MINOR>.<PATCH>
```

The `release.yml` workflow will automatically run tests and create a GitHub Release with auto-generated release notes.

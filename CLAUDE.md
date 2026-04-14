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

### Code Quality
- `composer phpstan` - Run static analysis with PHPStan (level 9)
- `composer cs` - Check code style with Laravel Pint
- `composer cs:fix` - Fix code style issues with Laravel Pint

### Documentation
- `cd docs && bun run dev` - Start VitePress dev server
- `cd docs && bun run build` - Build documentation site

## Architecture Overview

### Core Components

**Client Layer** (`src/Client/`)
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

**Support** (`src/Support/`)
- `Escaper.php` - Value escaping and SQL injection prevention

**Exceptions** (`src/Exceptions/`)
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
- `ClickHouse\Client\` - Core client functionality
- `ClickHouse\Laravel\` - Laravel framework integration
- `ClickHouse\Support\` - Utility classes
- `ClickHouse\Exceptions\` - Custom exceptions

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

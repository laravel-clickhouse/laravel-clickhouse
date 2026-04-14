# Laravel ClickHouse

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laravel-clickhouse/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/laravel-clickhouse/laravel-clickhouse)
[![License](https://img.shields.io/packagist/l/laravel-clickhouse/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/laravel-clickhouse/laravel-clickhouse)
[![PHP Version](https://img.shields.io/packagist/php-v/laravel-clickhouse/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/laravel-clickhouse/laravel-clickhouse)

A ClickHouse database driver for Laravel. Provides a familiar Eloquent Model, Query Builder, and Schema Builder with full support for ClickHouse-specific features.

## Features

- **Eloquent Model** support with non-incrementing IDs
- **Query Builder** with ClickHouse extensions — ARRAY JOIN, FINAL clause, CTE (WITH), set operations (UNION/INTERSECT/EXCEPT DISTINCT), ClickHouse-specific joins (ANY, SEMI, ANTI, ASOF), empty/notEmpty checks, SETTINGS clause
- **Schema Builder** with ClickHouse DDL — ENGINE, PARTITION BY, ORDER BY, LowCardinality, Array types, index granularity
- **Lightweight DELETE** with partition targeting
- **Parallel query execution** via Guzzle async HTTP pool
- **Two HTTP transports** — Guzzle (default) and Curl (phpclickhouse)
- **Laravel migrations** with ClickHouse-compatible migration repository
- PHP 8.2+, Laravel 11+

## Installation

```bash
composer require laravel-clickhouse/laravel-clickhouse
```

The package uses Laravel's auto-discovery — no manual service provider registration needed.

Add a ClickHouse connection to your `config/database.php`:

```php
'connections' => [
    // ...

    'clickhouse' => [
        'driver'   => 'clickhouse',
        'host'     => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port'     => env('CLICKHOUSE_PORT', 8123),
        'database' => env('CLICKHOUSE_DATABASE', 'default'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
    ],
],
```

For full configuration options, see [Installation & Configuration](docs/docs/installation.md).

## Quick Start

### Query Builder

```php
// Basic query with FINAL clause (merges data at query time)
$events = DB::connection('clickhouse')
    ->table('events', final: true)
    ->where('user_id', 1)
    ->get();

// ARRAY JOIN to expand array columns
$results = DB::connection('clickhouse')
    ->table('events')
    ->arrayJoin('tags', 'tag')
    ->where('tag', 'important')
    ->get();

// ClickHouse-specific join
$results = DB::connection('clickhouse')
    ->table('events')
    ->asofJoin('metrics', 'events.timestamp', 'metrics.timestamp')
    ->get();
```

### Eloquent Model

```php
use ClickHouse\Laravel\Eloquent\Model;

class Event extends Model
{
    protected $connection = 'clickhouse';
    protected $table = 'events';
}

// Query as usual
$events = Event::where('user_id', 1)->get();

// Lightweight delete with partition
Event::where('user_id', 1)->delete(lightweight: true, partition: '202301');
```

### Schema Builder

```php
use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;

Schema::connection('clickhouse')->create('events', function (ClickHouseBlueprint $table) {
    $table->unsignedBigInteger('id');
    $table->string('name');
    $table->text('status')->lowCardinality();
    $table->array('tags', 'String');
    $table->dateTime('created_at');

    $table->engine('MergeTree()');
    $table->orderBy(['id', 'created_at']);
    $table->partitionBy('toYYYYMM(created_at)');
});
```

### Parallel Queries

```php
use ClickHouse\Laravel\Parallel;

$results = Parallel::get([
    'users'  => User::where('active', 1),
    'events' => Event::where('type', 'click'),
]);

// $results['users'] → Collection of User models
// $results['events'] → Collection of Event models
```

## Documentation

| Topic | Description |
|-------|-------------|
| [Installation & Configuration](docs/docs/installation.md) | Requirements, setup, configuration options |
| [Query Builder](docs/docs/query-builder.md) | ClickHouse-specific query features |
| [Eloquent Model](docs/docs/eloquent.md) | Model definition, CRUD operations |
| [Schema Builder & Migrations](docs/docs/schema.md) | Table creation, column types, indexes |
| [Parallel Queries](docs/docs/parallel-queries.md) | Concurrent query execution |
| [Advanced Topics](docs/docs/advanced.md) | Transports, raw queries, limitations |

## Testing

```bash
composer test
```

Tests require a ClickHouse server running on `127.0.0.1:8123`. See [phpunit.xml.dist](phpunit.xml.dist) for configuration.

```bash
composer phpstan   # Static analysis
composer cs        # Code style check
composer cs:fix    # Fix code style
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

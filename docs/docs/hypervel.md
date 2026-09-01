# Hypervel Support

The package ships a second bridge for [Hypervel](https://hypervel.org) 0.4 — a Laravel-style framework with native coroutine support built on Swoole. Both bridges share the same framework-agnostic core, so query behavior is identical between Laravel and Hypervel.

## Requirements

- PHP 8.4+
- ext-swoole 6.2+
- `hypervel/components` (or the split `hypervel/database` package) `~0.4`

## Installation

```bash
composer require laravel-clickhouse/laravel-clickhouse hypervel/components:~0.4
```

The package registers `ClickHouse\Hypervel\ClickHouseServiceProvider` through Hypervel's provider auto-discovery (`extra.hypervel.providers`).

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
        'https'    => env('CLICKHOUSE_HTTPS', false),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
        ],
    ],
],
```

Hypervel resolves connections through its coroutine-aware connection pool. Connections must be declared in the config file because Hypervel does not support `DatabaseManager::build()` or `connectUsing()` dynamic connections.

The `pool.connect_timeout` option controls how long the driver may spend opening an HTTP connection. Hypervel passes this value to the ClickHouse client, which applies it to either the Guzzle or Curl transport. Fractional values are supported. If this option is not set, Hypervel uses the pool's 10-second default. A top-level `connect_timeout` value on the connection takes precedence over the pool value.

## Usage

Everything works the same as the Laravel bridge — swap the namespace from `ClickHouse\Laravel` to `ClickHouse\Hypervel`:

```php
use ClickHouse\Hypervel\Eloquent\Model;

class Event extends Model
{
    protected ?string $connection = 'clickhouse';
}

Event::query()
    ->prewhere('date', '>=', '2026-01-01')
    ->sample(0.1)
    ->limitBy(5, 'user_id')
    ->get();
```

Schema migrations, the `Schema` facade (`ClickHouse\Hypervel\Facades\Schema`), and parallel queries (`ClickHouse\Hypervel\Parallel`) are all available with the same API as their Laravel counterparts.

## Connection and Pooling

ClickHouse speaks HTTP rather than PDO. The bridge extends Hypervel's driver-neutral connection and uses the ClickHouse client directly, without fake PDO objects or PDO methods. The usual query builder, schema builder, Eloquent, and database APIs remain the same.

Each pooled connection owns a logical ClickHouse client. The client creates a transport for each operation, so pooled connections do not retain a Guzzle or Curl transport between queries. Hypervel uses the connection's native driver hooks to:

- run `SELECT 1` during pool heartbeats;
- forget or replace the ClickHouse client during disconnects and reconnects;
- recycle clients when idle or lifetime limits expire; and
- restore connection state when a pooled connection is released.

You may call `getClient()` when you need the underlying ClickHouse client. The connection's `ping()` method runs the same `SELECT 1` health check used by the pool.

ClickHouse does not support transactions. Calls to `beginTransaction()`, `commit()`, `rollBack()`, and `transaction()` throw the same `LogicException` as the Laravel bridge.

## Parallel queries under Swoole

`selectParallelly()` and `ClickHouse\Hypervel\Parallel` use Guzzle's curl multi handle. Under Swoole's native curl hook this runs inside the coroutine scheduler — covered by `tests/Hypervel/Feature/Integration/ParallelTest.php`, which runs inside a coroutine like every feature test. In most cases, prefer launching multiple coroutines with regular queries — the connection pool already gives you concurrency — and reserve `Parallel` for porting code from the Laravel bridge.

## Testing

Use Hypervel's native `RefreshDatabase`, `DatabaseMigrations`, and
`DatabaseTruncation` traits. Hypervel discovers the connections used by your
migrations and wipes each existing database before rebuilding the schema. See
[Testing](./testing.md#hypervel) for the available strategies and ClickHouse's
transaction limitation.

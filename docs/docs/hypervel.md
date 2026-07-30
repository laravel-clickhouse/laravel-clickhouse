# Hypervel Support

The package ships a second bridge for [Hypervel](https://hypervel.org) 0.4 — a Laravel-style framework with native coroutine support built on Swoole. Both bridges share the same framework-agnostic core (HTTP client, query compilation, DDL compilation), so query behaviour is identical between Laravel and Hypervel.

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
        ],
    ],
],
```

Hypervel resolves connections through its coroutine-aware connection pool. Each pooled connection carries its own HTTP client (Guzzle keep-alive), so concurrent coroutines never share an in-flight HTTP request. Connections must be declared in the config file — Hypervel's `DatabaseManager::build()` / `connectUsing()` dynamic connections are not supported by the framework.

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

## How the bridge differs from a PDO driver

ClickHouse speaks HTTP, not PDO. The bridge integrates with Hypervel's pool without pretending to be a PDO driver:

- `getPdo()` / `getReadPdo()` throw a `RuntimeException` — use `getClient()` to reach the underlying HTTP client.
- Transactions throw `LogicException` — same type and message as the Laravel bridge, so cross-framework code can catch one exception.
- The pool heartbeat is a no-op: `PooledConnection::ping()` only checks raw PDO handles, and this connection has none. Idle-timeout and max-lifetime eviction still apply; a recycled connection gets a fresh HTTP client via the driver resolver.
- `Connection::ping()` is available for application-level health checks — it runs `SELECT 1` over HTTP.

## Parallel queries under Swoole

`selectParallelly()` and `ClickHouse\Hypervel\Parallel` use Guzzle's curl multi handle. Under Swoole's native curl hook this runs inside the coroutine scheduler — covered by the coroutine test suite (`tests/Hypervel/Feature/Coroutine/`). In most cases, prefer launching multiple coroutines with regular queries — the connection pool already gives you concurrency — and reserve `Parallel` for porting code from the Laravel bridge.

## Testing

The bridge ships ClickHouse-aware testing traits under
`ClickHouse\Hypervel\Testing` — `RefreshDatabase`, `DatabaseMigrations` and
`DatabaseTruncation` — mirroring the Laravel bridge. See
[Testing](./testing.md#hypervel) for strategies and caveats.

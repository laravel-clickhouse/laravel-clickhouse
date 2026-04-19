# Testing

- [Overview](#overview)
- [Trait Compatibility](#trait-compatibility)
- [Recommended Setup](#recommended-setup)
- [Single ClickHouse Connection](#single-clickhouse-connection)
- [SQLite + ClickHouse (Combined)](#sqlite--clickhouse-combined)
- [Pure SQLite (No ClickHouse)](#pure-sqlite-no-clickhouse)
- [Caveats](#caveats)

## Overview

Tests typically need a way to reset database state between runs. Laravel ships three testing traits:

| Trait | Mechanism |
|-------|-----------|
| `RefreshDatabase` | Wraps each test in a transaction and rolls it back |
| `DatabaseTruncation` | Migrates once, then `TRUNCATE`s tables between tests |
| `DatabaseMigrations` | Runs `migrate:fresh` before each test, `migrate:rollback` after |

ClickHouse has no real transactions, so this package's `Connection` throws `LogicException` from `beginTransaction()`, `commit()`, `rollBack()`, and `transaction()`. `RefreshDatabase` therefore cannot wrap a ClickHouse connection — exclude it from `$connectionsToTransact` and use `DatabaseTruncation` or `DatabaseMigrations` for isolation on the ClickHouse side.

## Trait Compatibility

| Trait | ClickHouse connection | SQLite/MySQL/PostgreSQL connection |
|-------|-----------------------|------------------------------------|
| `RefreshDatabase` | ✗ `beginTransaction()` throws `LogicException`. Do not list ClickHouse in `$connectionsToTransact` | Works as in vanilla Laravel |
| `DatabaseTruncation` | ✓ Uses native `TRUNCATE TABLE` (not supported on `Distributed` / `View` engines) | Works as in vanilla Laravel |
| `DatabaseMigrations` | ✓ Uses the package's custom migration repository. For multi-connection migrations use `ClickHouse\Laravel\Testing\DatabaseMigrations` (see below) | Works as in vanilla Laravel |

For per-test isolation on a ClickHouse connection, use **`DatabaseTruncation`** (fast) or **`DatabaseMigrations`** (slower but more thorough). `RefreshDatabase` is still useful for non-ClickHouse connections in the same test class.

## Recommended Setup

1. Configure your `clickhouse` connection (and any other connections you use) in `config/database.php`.
2. Extend Laravel's built-in `Tests\TestCase` and add the trait that fits your isolation needs.
3. List the connections you want the trait to operate on via the matching property on your test class.

```php
// RefreshDatabase: ClickHouse cannot be listed — beginTransaction() throws.
protected $connectionsToTransact = ['sqlite'];

// DatabaseTruncation / multi-connection DatabaseMigrations: list every
// connection you want the trait to clean.
protected $connectionsToTruncate = ['sqlite', 'clickhouse'];
protected $connectionsToMigrate = ['sqlite', 'clickhouse'];
```

## Single ClickHouse Connection

When the only connection under test is ClickHouse, prefer `DatabaseTruncation`:

```php
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventsTest extends TestCase
{
    use DatabaseTruncation;

    protected $connectionsToTruncate = ['clickhouse'];

    public function testInsertsAnEvent(): void
    {
        DB::connection('clickhouse')->table('events')->insert(['id' => 1, 'name' => 'login']);

        $this->assertSame(1, DB::connection('clickhouse')->table('events')->count());
    }
}
```

If your migrations or table engines do not work with `TRUNCATE`, switch to `DatabaseMigrations`:

```php
use Illuminate\Foundation\Testing\DatabaseMigrations;

class EventsTest extends TestCase
{
    use DatabaseMigrations;

    // tests/...
}
```

## SQLite + ClickHouse (Combined)

A common setup is using SQLite for application data and ClickHouse for analytical data. List both connections in the trait property:

```php
use Illuminate\Foundation\Testing\DatabaseTruncation;

class AnalyticsTest extends TestCase
{
    use DatabaseTruncation;

    protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

    public function testWritesToBoth(): void
    {
        // ...
    }
}
```

`RefreshDatabase` is also valid here, but **only** with SQLite in `$connectionsToTransact`:

```php
protected $connectionsToTransact = ['sqlite'];
```

If you list `clickhouse`, `beginTransaction()` will throw `LogicException`. SQLite still rolls back per test; ClickHouse data must be cleaned via `DatabaseTruncation` or `DatabaseMigrations` if isolation matters.

### Multi-connection `DatabaseMigrations`

`migrate:fresh` only drops tables on the connection passed via `--database`, but always re-runs every registered migration (each landing on the connection it declares via `$connection`). When migrations target more than one connection, the secondary connection's tables accumulate across test classes and `CREATE TABLE` eventually conflicts.

The package ships a drop-in replacement that accepts a `$connectionsToMigrate` property (mirroring `$connectionsToTruncate`) and wipes secondary connections via `db:wipe` before the standard `migrate:fresh`:

```php
use ClickHouse\Laravel\Testing\DatabaseMigrations;

class AnalyticsTest extends TestCase
{
    use DatabaseMigrations;

    protected $connectionsToMigrate = ['sqlite', 'clickhouse'];

    // ...
}
```

The default connection is skipped automatically (it's already covered by `migrate:fresh`).

## Pure SQLite (No ClickHouse)

The package's service provider does not interfere with non-ClickHouse connections. All three traits work exactly as they do in vanilla Laravel for SQLite, MySQL, or PostgreSQL connections.

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    // ...
}
```

::: tip In-memory SQLite
`:memory:` works out of the box with `RefreshDatabase` (Laravel preserves the PDO between tests) and with `DatabaseMigrations` (each test re-runs `migrate:fresh` against the new connection). It does **not** work with `DatabaseTruncation`: that trait migrates once and assumes the schema persists, but each reconnect to `:memory:` gives a fresh database. For `DatabaseTruncation` use a file-based SQLite path, or a shared-cache URI like `file:tests?mode=memory&cache=shared` plus a keepalive PDO held for the lifetime of the test class.
:::

## Caveats

### RefreshDatabase on a ClickHouse connection

ClickHouse has no transactions, so `Connection::beginTransaction()` (and `commit`, `rollBack`, `transaction`) throw `LogicException`. To use `RefreshDatabase` in a test class that also touches ClickHouse, exclude the ClickHouse connection from `$connectionsToTransact` and use `DatabaseTruncation` or `DatabaseMigrations` for ClickHouse-side isolation.

### TRUNCATE engine compatibility

ClickHouse supports `TRUNCATE TABLE` for `Memory`, `MergeTree` family, and most ordinary engines. It does **not** work on `Distributed` or `View` engines. If your schema includes those, choose `DatabaseMigrations` instead.

### `migration.repository` rebinding

The service provider rebinds `migration.repository` as an app-wide singleton to a ClickHouse-aware implementation as soon as `MigrateInstallCommand` is resolved. The repository writes each migration against the connection that the migration itself declares (via `protected $connection = '...'`), so SQLite migrations still land on SQLite. In practice this means the rebinding is transparent, but it does silently replace Laravel's default repository — keep this in mind if you bind your own.


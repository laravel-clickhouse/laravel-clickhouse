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

This package overrides `Connection::beginTransaction` / `commit` / `rollBack` / `transaction` so they no longer crash on the missing PDO, but ClickHouse has no real transactions — the override is a no-op that only tracks nesting level and fires events.

## Trait Compatibility

| Trait | ClickHouse connection | SQLite/MySQL/PostgreSQL connection |
|-------|-----------------------|------------------------------------|
| `RefreshDatabase` | ⚠ Works without crashing, but **does not isolate** — data persists between tests | Works as in vanilla Laravel |
| `DatabaseTruncation` | Works (uses native `TRUNCATE TABLE`; not supported on `Distributed` / `View` engines) | Works as in vanilla Laravel |
| `DatabaseMigrations` | Works (uses the package's custom migration repository) | Works as in vanilla Laravel |

For real per-test isolation on a ClickHouse connection, use **`DatabaseTruncation`** (fast) or **`DatabaseMigrations`** (slower but more thorough).

## Recommended Setup

1. Configure your `clickhouse` connection (and any other connections you use) in `config/database.php`.
2. Extend Laravel's built-in `Tests\TestCase` and add the trait that fits your isolation needs.
3. List the connections you want the trait to operate on via the `$connectionsToTransact` or `$connectionsToTruncate` property on your test class.

```php
protected $connectionsToTransact = ['sqlite', 'clickhouse'];
protected $connectionsToTruncate = ['sqlite', 'clickhouse'];
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

`RefreshDatabase` is also valid here, but be aware of the asymmetric behaviour:

- The SQLite connection is rolled back per test as expected.
- The ClickHouse connection's "transactions" are no-ops, so any ClickHouse data you write **stays around** until the next time something cleans it.

If you want symmetric isolation, prefer `DatabaseTruncation` or `DatabaseMigrations`.

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

Because ClickHouse transactions are no-ops, `RefreshDatabase` provides **no isolation** for ClickHouse data — it only prevents the trait from crashing. Use `DatabaseTruncation` or `DatabaseMigrations` for real isolation.

### TRUNCATE engine compatibility

ClickHouse supports `TRUNCATE TABLE` for `Memory`, `MergeTree` family, and most ordinary engines. It does **not** work on `Distributed` or `View` engines. If your schema includes those, choose `DatabaseMigrations` instead.

### `migration.repository` rebinding

The service provider rebinds `migration.repository` as an app-wide singleton to a ClickHouse-aware implementation as soon as `MigrateInstallCommand` is resolved. The repository writes each migration against the connection that the migration itself declares (via `protected $connection = '...'`), so SQLite migrations still land on SQLite. In practice this means the rebinding is transparent, but it does silently replace Laravel's default repository — keep this in mind if you bind your own.

### `afterCommit` callbacks

`DB::afterCommit(...)` callbacks registered on a ClickHouse connection fire as soon as the outermost (fake) transaction commits. This matches what Laravel testing traits expect, but be aware that "after commit" is effectively immediate on ClickHouse.

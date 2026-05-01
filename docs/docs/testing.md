# Testing

- [Overview](#overview)
- [Trait Compatibility](#trait-compatibility)
- [Recommended Setup](#recommended-setup)
- [Single ClickHouse Connection](#single-clickhouse-connection)
- [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined)
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
| `DatabaseTruncation` | ✓ Uses native `TRUNCATE TABLE` (not supported on `Distributed` / `View` engines) | Works as in vanilla Laravel — bare `:memory:` SQLite is **not** supported, see [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined) |
| `DatabaseMigrations` | ✓ Uses the package's custom migration repository. For multi-connection migrations use `ClickHouse\Laravel\Testing\DatabaseMigrations` (see below) | Works as in vanilla Laravel |

For per-test isolation on a ClickHouse connection, use **`DatabaseMigrations`** (safe default, works with `:memory:` SQLite alongside) or **`DatabaseTruncation`** (faster but assumes schema persists, see the SQLite caveat below). `RefreshDatabase` is still useful for non-ClickHouse connections in the same test class.

## Recommended Setup

1. Configure your `clickhouse` connection (and any other connections you use) in `config/database.php`.
2. Extend Laravel's built-in `Tests\TestCase` and add **one** of the testing traits that fits your isolation needs.
3. On that test class, set the property that matches the chosen trait — list the connections it should operate on:

```php
// RefreshDatabase — ClickHouse cannot be listed (beginTransaction() throws).
protected $connectionsToTransact = ['sqlite'];

// DatabaseTruncation — list every connection you want truncated between tests.
protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

// ClickHouse\Laravel\Testing\DatabaseMigrations — list every connection a
// migration in this class targets.
protected $connectionsToMigrate = ['sqlite', 'clickhouse'];
```

You only need the property that matches the trait you actually used. The examples below show each in context.

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

A common setup uses SQLite for application data and ClickHouse for analytical data. The recommended starting point is the package's `ClickHouse\Laravel\Testing\DatabaseMigrations` — it works with bare `:memory:` SQLite out of the box and handles multi-connection migration wipe automatically:

```php
use ClickHouse\Laravel\Testing\DatabaseMigrations;

class AnalyticsTest extends TestCase
{
    use DatabaseMigrations;

    protected $connectionsToMigrate = ['sqlite', 'clickhouse'];

    public function testWritesToBoth(): void
    {
        // ...
    }
}
```

Why this is the safer default: `migrate:fresh --database=X` only drops tables on `X`, but every registered migration re-runs each invocation and lands on whatever connection it declares via `protected $connection`. Across test classes the *other* connection's tables would accumulate and the next `CREATE TABLE` would collide. The package's `DatabaseMigrations` pre-wipes every connection in `$connectionsToMigrate` before `migrate:fresh`, so each test starts with a clean schema on every listed connection. Schema is rebuilt per test, so SQLite can stay on bare `:memory:`.

### Faster: `DatabaseTruncation` (with shared-connection SQLite)

`DatabaseTruncation` skips the per-test `migrate:fresh` and only truncates tables — meaningfully faster for large schemas. The catch on the SQLite side: it migrates once and then assumes the schema persists across reconnects. A bare `:memory:` private database can't honour that assumption — it dies the moment its only PDO disconnects, and Laravel's in-memory PDO preservation only kicks in for `RefreshDatabase`.

To use `DatabaseTruncation` with SQLite, switch the connection to a shared-cache URI and hold a keepalive PDO for the lifetime of the test class:

```php
use PDO;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    // Named form (`file:NAME?mode=memory&cache=shared`) is required, not
    // the shorter `file::memory:?cache=shared`. Laravel's SQLiteBuilder
    // text-matches the database string for `?mode=memory`/`&mode=memory`
    // when deciding whether `db:wipe` should drop tables via SQL or just
    // truncate a literal file. The shorter form fails that check and
    // leaves stale tables in the in-memory DB across `migrate:fresh` runs.
    private const SQLITE_URI = 'file:testing?mode=memory&cache=shared';

    protected static ?PDO $sqliteKeepalive = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$sqliteKeepalive = new PDO('sqlite:'.self::SQLITE_URI);
    }

    public static function tearDownAfterClass(): void
    {
        static::$sqliteKeepalive = null;

        parent::tearDownAfterClass();
    }
}
```

Point the SQLite connection in `config/database.php` at `self::SQLITE_URI` (or whatever constant you expose on the test base). With the schema preserved across reconnects, `DatabaseTruncation` works as advertised:

```php
use ClickHouse\Laravel\Testing\DatabaseTruncation;

class AnalyticsTest extends TestCase
{
    use DatabaseTruncation;

    protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

    // ...
}
```

The package's `DatabaseTruncation` (note: not the framework's) takes care of the same multi-connection wipe before the one-time `migrate:fresh` — same reasoning as for `DatabaseMigrations` above.

If your SQLite is file-based (or you're using MySQL/Postgres for the relational side), no extra setup is needed — only `:memory:` requires the keepalive trick.

### Alternative: `RefreshDatabase` for the SQLite side

`RefreshDatabase` is also valid here, but **only** with SQLite in `$connectionsToTransact`:

```php
protected $connectionsToTransact = ['sqlite'];
```

If you list `clickhouse`, `beginTransaction()` will throw `LogicException`. SQLite still rolls back per test via Laravel's preserved in-memory PDO; ClickHouse data must be cleaned via `DatabaseTruncation` or `DatabaseMigrations` if isolation matters.

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
`:memory:` works out of the box with `RefreshDatabase` (Laravel preserves the PDO between tests) and with `DatabaseMigrations` (each test re-runs `migrate:fresh` against the new connection). It does **not** work with `DatabaseTruncation`: that trait migrates once and assumes the schema persists, but each reconnect to `:memory:` gives a fresh database. For `DatabaseTruncation` either use a file-based SQLite path, or apply the shared-cache + keepalive pattern shown in [SQLite + ClickHouse → Faster: `DatabaseTruncation`](#faster-databasetruncation-with-shared-connection-sqlite).
:::

## Caveats

### RefreshDatabase on a ClickHouse connection

ClickHouse has no transactions, so `Connection::beginTransaction()` (and `commit`, `rollBack`, `transaction`) throw `LogicException`. To use `RefreshDatabase` in a test class that also touches ClickHouse, exclude the ClickHouse connection from `$connectionsToTransact` and use `DatabaseTruncation` or `DatabaseMigrations` for ClickHouse-side isolation.

### TRUNCATE engine compatibility

ClickHouse supports `TRUNCATE TABLE` for `Memory`, `MergeTree` family, and most ordinary engines. It does **not** work on `Distributed` or `View` engines. If your schema includes those, choose `DatabaseMigrations` instead.

### `migration.repository` rebinding

The service provider rebinds `migration.repository` as an app-wide singleton to a ClickHouse-aware implementation as soon as `MigrateInstallCommand` is resolved. The repository writes each migration against the connection that the migration itself declares (via `protected $connection = '...'`), so SQLite migrations still land on SQLite. In practice this means the rebinding is transparent, but it does silently replace Laravel's default repository — keep this in mind if you bind your own.

### `DatabaseTruncation` and per-class migration paths

`DatabaseTruncation` (the framework's trait, which this package extends) runs `migrate:fresh` exactly once per process, gated by the static `RefreshDatabaseState::$migrated` flag. Once set, subsequent test classes using the trait skip their own `migrate:fresh` and only truncate the listed connections.

This is correct for typical Laravel apps where every test class sees the same set of migrations from `database/migrations/` — the schema is uniform, so reusing it across classes works.

If your test classes register **different** migration paths via `defineDatabaseMigrations()` (common in package testbenches that demo single-connection vs multi-connection setups), the latched state misleads later classes into thinking their schema is ready when it isn't: their `migrate:fresh` is skipped and queries hit tables that were never created. Reset the flag in your test base class's `setUpBeforeClass()`:

```php
use Illuminate\Foundation\Testing\RefreshDatabaseState;

public static function setUpBeforeClass(): void
{
    parent::setUpBeforeClass();

    RefreshDatabaseState::$migrated = false;
}
```

Most application test suites don't hit this — it surfaces only when migration registration varies per test class.


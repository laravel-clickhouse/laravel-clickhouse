# Testing

- [Overview](#overview)
- [Trait Compatibility](#trait-compatibility)
- [Recommended Setup](#recommended-setup)
- [ClickHouse Migrations in a Separate Directory](#clickhouse-migrations-in-a-separate-directory)
- [Single ClickHouse Connection](#single-clickhouse-connection)
- [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined)
- [Pure SQLite (No ClickHouse)](#pure-sqlite-no-clickhouse)
- [Caveats](#caveats)

## Overview

Tests typically need a way to reset database state between runs. Laravel ships these testing traits:

| Trait | Mechanism |
|-------|-----------|
| `RefreshDatabase` | Wraps each test in a transaction and rolls it back |
| `DatabaseTransactions` | Same rollback mechanism as `RefreshDatabase`, but never runs migrations — the schema must already exist |
| `DatabaseTruncation` | Migrates once, then `TRUNCATE`s tables between tests |
| `DatabaseMigrations` | Runs `migrate:fresh` before each test, `migrate:rollback` after |

ClickHouse has no real transactions, so this package's `Connection` throws `LogicException` from `beginTransaction()`, `commit()`, `rollBack()`, and `transaction()`. The transaction-based traits (`RefreshDatabase`, `DatabaseTransactions`) therefore cannot wrap a ClickHouse connection — exclude it from `$connectionsToTransact` and use `DatabaseTruncation` or `DatabaseMigrations` for isolation on the ClickHouse side.

## Trait Compatibility

| Trait | ClickHouse connection | SQLite/MySQL/PostgreSQL connection |
|-------|-----------------------|------------------------------------|
| `RefreshDatabase` | ✗ `beginTransaction()` throws `LogicException` — never list ClickHouse in `$connectionsToTransact`. For combined setups, use `ClickHouse\Laravel\Testing\RefreshDatabase` stacked with `DatabaseTruncation` (the hybrid, see below) | Works as in vanilla Laravel |
| `DatabaseTransactions` | ✗ Same as `RefreshDatabase` — rollback isolation is physically impossible without transactions | Works as in vanilla Laravel |
| `DatabaseTruncation` | ✓ Uses native `TRUNCATE TABLE` (not supported on `Distributed` / `View` engines) | Works as in vanilla Laravel — bare `:memory:` SQLite is **not** supported, see [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined) |
| `DatabaseMigrations` | ✓ Uses the package's custom migration repository. For multi-connection migrations use `ClickHouse\Laravel\Testing\DatabaseMigrations` (see below) | Works as in vanilla Laravel |

For per-test isolation on a ClickHouse connection, use **`DatabaseTruncation`** (fast, `TRUNCATE` between tests) or **`DatabaseMigrations`** (rebuilds schema per test, for engines `TRUNCATE` cannot handle). For combined SQLite + ClickHouse setups, the recommended default is the **hybrid** — package `RefreshDatabase` + package `DatabaseTruncation` stacked in one class (see [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined)).

## Recommended Setup

1. Configure your `clickhouse` connection (and any other connections you use) in `config/database.php`.
2. Extend Laravel's built-in `Tests\TestCase` and add the testing trait that fits your isolation needs — or stack two for hybrid isolation (see [SQLite + ClickHouse (Combined)](#sqlite-clickhouse-combined)).
3. On that test class, set the property that matches each chosen trait — list the connections it should operate on:

```php
// RefreshDatabase / DatabaseTransactions — ClickHouse cannot be listed
// (beginTransaction() throws).
protected $connectionsToTransact = ['sqlite'];

// DatabaseTruncation — list every connection you want truncated between tests.
protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

// ClickHouse\Laravel\Testing\DatabaseMigrations — list every connection a
// migration in this class targets.
protected $connectionsToMigrate = ['sqlite', 'clickhouse'];
```

You only need the properties that match the traits you actually used. The examples below show each in context.

## ClickHouse Migrations in a Separate Directory

Keeping ClickHouse migrations apart from the relational ones (for example `database/migrations/clickhouse/`) works with the standard Laravel mechanisms — no package-specific wiring is needed. Each migration declares its target connection via `protected $connection`, and the directory is registered as an additional migration path:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->loadMigrationsFrom(database_path('migrations/clickhouse'));
}
```

```php
// database/migrations/clickhouse/2026_01_01_000000_create_events_table.php
use ClickHouse\Laravel\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->string('name');
            $table->engine('MergeTree');
            $table->orderBy('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExistsSync('events');
    }
};
```

`migrate`, `migrate:fresh` and the testing traits pick up every registered path automatically, and each migration runs against the connection it declares. In an Orchestra Testbench setup, register the path with `load_migration_paths($this->app, __DIR__.'/database/migrations/clickhouse')` inside `defineDatabaseMigrations()` instead.

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

A common setup uses SQLite for application data and ClickHouse for analytical data. The recommended default is the **hybrid**: the package's `RefreshDatabase` for the SQLite side, the package's `DatabaseTruncation` for the ClickHouse side:

```php
use ClickHouse\Laravel\Testing\DatabaseTruncation;
use ClickHouse\Laravel\Testing\RefreshDatabase;

class AnalyticsTest extends TestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;

    protected $connectionsToTransact = ['sqlite'];

    protected $connectionsToTruncate = ['clickhouse'];
}
```

Every connection resets before every test — SQLite by transaction rollback, ClickHouse by `TRUNCATE` — and bare `:memory:` SQLite works with no extra setup, because `RefreshDatabase` is the one trait the framework preserves the in-memory PDO for.

The lifecycle: `RefreshDatabase` owns the one-time `migrate:fresh`, preceded by the package's pre-wipe of every connection the class works with — derived as the union of `$connectionsToTransact` and `$connectionsToTruncate`, no extra property needed — which is what stops leftover ClickHouse tables from colliding on `CREATE TABLE`. It then latches `RefreshDatabaseState::$migrated`, so `DatabaseTruncation`'s own first-run branch short-circuits and it only ever truncates between tests. Keep `$connectionsToTransact` and `$connectionsToTruncate` disjoint: a connection resets by rollback *or* by truncation, never both.

### Which trait pairs can stack

Only two combinations are viable — the two shown on this page:

| Combination | Status |
|---|---|
| `RefreshDatabase` + `DatabaseTruncation` | ✓ The recommended hybrid above |
| `DatabaseTransactions` + `DatabaseTruncation` | ✓ Alternative hybrid, see [Last resort](#last-resort-truncating-sqlite-too-shared-connection-memory) |
| `RefreshDatabase` + `DatabaseTransactions` | ✗ PHP fatal — both define `beginDatabaseTransaction()` |
| `RefreshDatabase` + `DatabaseMigrations` | ✗ PHP fatal — both define `refreshTestDatabase()` |
| `DatabaseTransactions` + `DatabaseMigrations` | ✗ Composes but pointless — `DatabaseMigrations` already resets everything per test, the transaction adds nothing |
| `DatabaseTruncation` + `DatabaseMigrations` | ✗ Contradictory — one builds the schema once and truncates, the other rebuilds it per test; their `RefreshDatabaseState::$migrated` handling fights |

The rule behind the table: a valid stack needs **exactly one schema owner**. Three traits build schema via `migrate:fresh` (`RefreshDatabase`, `DatabaseTruncation`, `DatabaseMigrations`) and stacking two of them either collides at the PHP level or fights over the migrate lifecycle; `DatabaseTransactions` builds nothing and can only ride on top of one owner. That leaves the two viable pairs — and `DatabaseMigrations` pairs with nothing useful, because its per-test rebuild already resets every connection on its own.

And one connection-list rule regardless of the pair: the ClickHouse connection can never appear in `$connectionsToTransact` (`beginTransaction()` throws).

### Simpler: `DatabaseMigrations` (slower, no trait stacking)

If stacking two traits feels like too many moving parts, the package's `DatabaseMigrations` covers the same scenario with a single trait — at the cost of re-running every migration before every test:

```php
use ClickHouse\Laravel\Testing\DatabaseMigrations;

class AnalyticsTest extends TestCase
{
    use DatabaseMigrations;

    protected $connectionsToMigrate = ['sqlite', 'clickhouse'];
}
```

Same pre-wipe reasoning as above; schema is rebuilt per test, so bare `:memory:` SQLite works here too. Prefer the hybrid when suite speed matters.

### Last resort: truncating SQLite too (shared-connection `:memory:`)

If you cannot use `RefreshDatabase` on the SQLite side — for example the code under test manages its own transactions and would conflict with the wrapping transaction — the remaining option is truncating both connections. `DatabaseTruncation` migrates once and then assumes the SQLite schema persists across reconnects. A bare `:memory:` private database can't honour that assumption — it dies the moment its only PDO disconnects, and Laravel's in-memory PDO preservation only kicks in for `RefreshDatabase`.

To make it work, switch the connection to a shared-cache URI and hold a keepalive PDO for the lifetime of the test class:

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

On this shared-connection setup, `DatabaseTransactions` can also stack with the package's `DatabaseTruncation` in place of `RefreshDatabase` — it never runs migrations, so the truncation trait's own first-run `migrate:fresh` builds the schema instead. The rules are the same: disjoint connection lists, ClickHouse never in `$connectionsToTransact`.

### `RefreshDatabase` alone is not enough for ClickHouse

The package's `RefreshDatabase` on its own only fixes the schema collision (via the pre-wipe). It gives no per-test data isolation on ClickHouse — rollback happens only on the transacting connections, so rows written to ClickHouse in one test remain visible to the next. That is why the recommended hybrid pairs it with `DatabaseTruncation`: whenever tests write to ClickHouse, stack both traits.

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
`:memory:` works out of the box with `RefreshDatabase` (Laravel preserves the PDO between tests) and with `DatabaseMigrations` (each test re-runs `migrate:fresh` against the new connection). It does **not** work with `DatabaseTruncation`: that trait migrates once and assumes the schema persists, but each reconnect to `:memory:` gives a fresh database. For `DatabaseTruncation` either use a file-based SQLite path, or apply the shared-cache + keepalive pattern shown in [SQLite + ClickHouse → Last resort](#last-resort-truncating-sqlite-too-shared-connection-memory).
:::

## Caveats

### RefreshDatabase / DatabaseTransactions on a ClickHouse connection

ClickHouse has no transactions, so `Connection::beginTransaction()` (and `commit`, `rollBack`, `transaction`) throw `LogicException`. To use `RefreshDatabase` or `DatabaseTransactions` in a test class that also touches ClickHouse, exclude the ClickHouse connection from `$connectionsToTransact` and use `DatabaseTruncation` or `DatabaseMigrations` for ClickHouse-side isolation.

This failure is loud by design: both traits default `$connectionsToTransact` to the default connection, so if ClickHouse is your default and the property is missing, the very first test aborts with the `LogicException` instead of silently running without isolation and leaking data between tests.

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


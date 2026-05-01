<?php

namespace ClickHouse\Tests\Testbench;

use ClickHouse\Laravel\ClickHouseServiceProvider;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Shared-cache in-memory SQLite URI for the entire test run.
     *
     * This testbench exists to demonstrate the package's testing traits —
     * including DatabaseTruncation — against a single SQLite connection.
     * DatabaseTruncation runs `migrate:fresh` once and then assumes the
     * schema persists across reconnects, so bare `:memory:` is unusable
     * here: a private in-memory DB dies the moment its only PDO
     * disconnects. A URI with `mode=memory&cache=shared` lets every PDO
     * opened in this process join the same in-memory DB, and the keepalive
     * PDO below holds the cache alive across Laravel-side reconnects.
     *
     * Why the named form (`file:testing?mode=memory&cache=shared`) and
     * not the simpler `file::memory:?cache=shared`: Laravel's
     * `Schema\SQLiteBuilder::dropAllTables()` decides whether to drop
     * tables via SQL or just `file_put_contents('', $database)` by
     * text-matching the database string for `:memory:`, `?mode=memory`,
     * or `&mode=memory`. `file::memory:?cache=shared` matches none of
     * those substrings (it has `:memory:` inside but not equal to it),
     * so `db:wipe` would silently truncate a literal file in the cwd
     * instead of dropping the in-memory tables — leaving stale data
     * across `migrate:fresh` calls. The named form satisfies the
     * `?mode=memory` substring check and routes through the SQL path.
     *
     * The URI is constant across test classes (same posture as the
     * shared ClickHouse / MySQL server you'd point at in production).
     * Cross-class isolation is provided by `RefreshDatabaseState` reset
     * + the package's pre-wipe in `beforeRefreshingDatabase` /
     * `beforeTruncatingDatabase`, not by physical DB separation.
     *
     * This setup is purely a demo concern. Application code consuming
     * the package's traits does not need it — bare `:memory:` is fine
     * for RefreshDatabase and DatabaseMigrations, and any persistent
     * database (file SQLite, MySQL, …) works for DatabaseTruncation.
     */
    private const SQLITE_URI = 'file:testing?mode=memory&cache=shared';

    /**
     * Long-lived PDO that keeps the shared in-memory database alive for
     * the entire test run. Lazily opened on the first class's setUp and
     * left in place — process exit releases it.
     */
    protected static ?PDO $sqliteKeepalive = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Force every testbench class to run its own `migrate:fresh` instead
        // of inheriting the previous class's latched state. The testbench
        // deliberately registers different migration paths per class via
        // `defineDatabaseMigrations()` (ClickHouse-only, SQLite-only, both)
        // to demo each scenario; without this reset, the second class
        // skips migrate:fresh and queries tables that were never created.
        // Application code with one global migration set never hits this —
        // see docs/docs/testing.md "Caveats" for the full explanation.
        RefreshDatabaseState::$migrated = false;

        self::$sqliteKeepalive ??= new PDO('sqlite:'.self::SQLITE_URI);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ClickHouseServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Custom sqlite driver wired for the demo testbench. It opens a
        // PDO directly against the shared-cache in-memory URI (see
        // SQLITE_URI above) so each Laravel-side reconnect lands on the
        // same in-memory DB that the keepalive PDO is holding. Wiring our
        // own driver here keeps the demo's SQLite layer self-contained and
        // independent of any stock-driver behaviour around URI-mode
        // database strings — application code never needs this.
        $app->resolving('db', function ($db) {
            $db->extend('sqlite_memory_shared', function (array $config, string $name) {
                $pdoFactory = function () use ($config): PDO {
                    $pdo = new PDO('sqlite:'.$config['database']);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    return $pdo;
                };

                return new SQLiteConnection(
                    $pdoFactory,
                    $config['database'],
                    $config['prefix'] ?? '',
                    array_merge($config, ['name' => $name]),
                );
            });
        });

        $app['config']->set('database.default', $this->defaultConnection());

        $app['config']->set('database.connections.clickhouse', [
            'driver' => 'clickhouse',
            ...static::clickHouseConfig(),
        ]);

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite_memory_shared',
            'database' => self::SQLITE_URI,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private static function clickHouseConfig(): array
    {
        return [
            'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
            'port' => (int) env('CLICKHOUSE_PORT', 8123),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', 'default'),
        ];
    }
}

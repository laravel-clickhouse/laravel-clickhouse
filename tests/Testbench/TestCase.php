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
     * Shared-cache in-memory SQLite URI for this test class. A bare ":memory:"
     * cannot be used because DatabaseTruncation / DatabaseMigrations disconnect
     * between tests and a private in-memory DB dies on disconnect. The
     * "file:xxx?mode=memory&cache=shared" URI lets multiple PDO instances
     * share the same in-memory DB inside one process, and the keepalive PDO
     * below holds it alive across reconnects.
     */
    protected static ?string $sqliteUri = null;

    /**
     * Long-lived PDO that keeps the shared in-memory database alive for the
     * lifetime of the test class. Cleared in tearDownAfterClass so the DB is
     * released between classes.
     */
    protected static ?PDO $sqliteKeepalive = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Reset static state so each test class re-runs migrate:fresh. Without
        // this the second class would see the first class's migrated flag,
        // skip migrations, and end up with stale table metadata.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        static::$sqliteUri = 'file:lch_tb_'.bin2hex(random_bytes(6)).'?mode=memory&cache=shared';
        static::$sqliteKeepalive = new PDO('sqlite:'.static::$sqliteUri);
    }

    public static function tearDownAfterClass(): void
    {
        // Releasing the last PDO referencing the shared in-memory cache lets
        // SQLite free the database. Subsequent classes get a fresh one.
        static::$sqliteKeepalive = null;
        static::$sqliteUri = null;

        parent::tearDownAfterClass();
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
        // Register a custom sqlite driver that accepts URI-mode database
        // strings. Laravel's stock SQLiteConnector calls realpath() on any
        // non-":memory:" database value, which would reject our shared-cache
        // URI. Each Laravel-side reconnect opens a fresh PDO against the same
        // URI, transparently sharing schema with the keepalive PDO.
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
            'database' => static::$sqliteUri,
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

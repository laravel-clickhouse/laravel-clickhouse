<?php

namespace ClickHouse\Tests\Testbench;

use ClickHouse\Laravel\ClickHouseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Per-class SQLite file path. File-based (not ":memory:") because Laravel's
     * DatabaseTruncation / DatabaseMigrations disconnect and reconnect between
     * tests, and ":memory:" loses its schema on every reconnect (a documented
     * Laravel limitation). A temp file gives a cheap ephemeral DB that is
     * removed in tearDownAfterClass.
     */
    protected static ?string $sqliteDatabasePath = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Reset static state so each test class re-runs migrate:fresh. Without
        // this the second class would see the first class's migrated flag,
        // skip migrations, and end up with stale table metadata.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        static::$sqliteDatabasePath = tempnam(sys_get_temp_dir(), 'lch_tb_').'.sqlite';
        touch(static::$sqliteDatabasePath);

        // migrate:fresh only drops tables on the default connection. In the
        // combined scenario ClickHouse-side tables would be left over and
        // cause TABLE_ALREADY_EXISTS. Wipe them directly via the HTTP API.
        static::resetClickHouseTestDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$sqliteDatabasePath !== null && file_exists(static::$sqliteDatabasePath)) {
            @unlink(static::$sqliteDatabasePath);
        }

        static::$sqliteDatabasePath = null;

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
        $app['config']->set('database.default', $this->defaultConnection());

        $app['config']->set('database.connections.clickhouse', [
            'driver' => 'clickhouse',
            'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
            'port' => (int) env('CLICKHOUSE_PORT', 8123),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', 'default'),
        ]);

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => static::$sqliteDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    /**
     * Drop every non-system table in the ClickHouse test database via HTTP.
     * We bypass the Laravel connection because this runs before the app boots.
     */
    protected static function resetClickHouseTestDatabase(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: '127.0.0.1';
        $port = getenv('CLICKHOUSE_PORT') ?: '8123';
        $database = getenv('CLICKHOUSE_DATABASE') ?: 'default';
        $username = getenv('CLICKHOUSE_USERNAME') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'default';

        $send = function (string $sql) use ($host, $port, $username, $password, $database): void {
            $url = sprintf(
                'http://%s:%s/?database=%s&default_format=JSON',
                $host,
                $port,
                urlencode($database),
            );

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Authorization: Basic '.base64_encode($username.':'.$password)."\r\n".
                                "Content-Type: text/plain\r\n",
                    'content' => $sql,
                    'ignore_errors' => true,
                    'timeout' => 5,
                ],
            ]);

            @file_get_contents($url, false, $context);
        };

        $listSql = sprintf("SELECT name FROM system.tables WHERE database = '%s' FORMAT JSONEachRow", addslashes($database));
        $url = sprintf(
            'http://%s:%s/?database=%s',
            $host,
            $port,
            urlencode($database),
        );
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Authorization: Basic '.base64_encode($username.':'.$password)."\r\n".
                            "Content-Type: text/plain\r\n",
                'content' => $listSql,
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false || $response === '') {
            return;
        }

        foreach (explode("\n", trim($response)) as $line) {
            /** @var array{name?: string}|null $row */
            $row = json_decode($line, true);

            if (! is_array($row) || empty($row['name'])) {
                continue;
            }

            $send('DROP TABLE IF EXISTS `'.$row['name'].'` SYNC');
        }
    }
}

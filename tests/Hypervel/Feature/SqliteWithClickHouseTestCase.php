<?php

namespace ClickHouse\Tests\Hypervel\Feature;

use function Hypervel\Testbench\load_migration_paths;

abstract class SqliteWithClickHouseTestCase extends TestCase
{
    protected array $connectionsToTruncate = ['sqlite', 'clickhouse'];

    protected array $connectionsToMigrate = ['sqlite', 'clickhouse'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'sqlite');
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            __DIR__.'/database/migrations',
            __DIR__.'/database/migrations/clickhouse',
        ]);
    }
}

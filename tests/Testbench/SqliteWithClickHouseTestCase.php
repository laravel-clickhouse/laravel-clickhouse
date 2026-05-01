<?php

namespace ClickHouse\Tests\Testbench;

use function Orchestra\Testbench\load_migration_paths;

abstract class SqliteWithClickHouseTestCase extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

    /** @var array<int, string> */
    protected $connectionsToMigrate = ['sqlite', 'clickhouse'];

    protected function defaultConnection(): string
    {
        return 'sqlite';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            __DIR__.'/database/migrations',
            __DIR__.'/database/migrations/clickhouse',
        ]);
    }
}

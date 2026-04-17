<?php

namespace ClickHouse\Tests\Testbench;

use function Orchestra\Testbench\load_migration_paths;

abstract class SqliteWithClickHouseTestCase extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = ['sqlite', 'clickhouse'];

    /** @var array<int, string> */
    protected $connectionsToTruncate = ['sqlite', 'clickhouse'];

    protected function defaultConnection(): string
    {
        return 'sqlite';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations');
    }
}

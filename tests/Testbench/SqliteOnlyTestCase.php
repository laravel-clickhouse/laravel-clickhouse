<?php

namespace ClickHouse\Tests\Testbench;

use function Orchestra\Testbench\load_migration_paths;

abstract class SqliteOnlyTestCase extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = ['sqlite'];

    /** @var array<int, string> */
    protected $connectionsToTruncate = ['sqlite'];

    protected function defaultConnection(): string
    {
        return 'sqlite';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations');
    }
}

<?php

namespace ClickHouse\Tests\Feature;

use function Orchestra\Testbench\load_migration_paths;

abstract class SqliteOnlyTestCase extends TestCase
{
    protected function defaultConnection(): string
    {
        return 'sqlite';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations');
    }
}

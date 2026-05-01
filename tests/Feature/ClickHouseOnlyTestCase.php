<?php

namespace ClickHouse\Tests\Feature;

use function Orchestra\Testbench\load_migration_paths;

abstract class ClickHouseOnlyTestCase extends TestCase
{
    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations/clickhouse');
    }
}

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing;

use ClickHouse\Tests\Hypervel\Feature\TestCase;

use function Hypervel\Testbench\load_migration_paths;

abstract class ClickHouseOnlyTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/../database/migrations/clickhouse');
    }
}

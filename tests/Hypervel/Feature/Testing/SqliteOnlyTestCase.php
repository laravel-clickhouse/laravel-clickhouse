<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing;

use ClickHouse\Tests\Hypervel\Feature\TestCase;

use function Hypervel\Testbench\load_migration_paths;

abstract class SqliteOnlyTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'sqlite');
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/../database/migrations');
    }
}

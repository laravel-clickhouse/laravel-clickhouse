<?php

namespace ClickHouse\Tests\Testbench;

use function Orchestra\Testbench\load_migration_paths;

abstract class ClickHouseOnlyTestCase extends TestCase
{
    /**
     * Empty so RefreshDatabase doesn't try to begin a transaction on the
     * ClickHouse connection (which throws LogicException).
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = [];

    /** @var array<int, string> */
    protected $connectionsToTruncate = ['clickhouse'];

    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations');
    }
}

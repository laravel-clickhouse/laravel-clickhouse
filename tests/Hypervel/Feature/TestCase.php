<?php

namespace ClickHouse\Tests\Hypervel\Feature;

use ClickHouse\Hypervel\ClickHouseServiceProvider;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * {@inheritDoc}
     *
     * The testbench override of this dispatcher only handles RefreshDatabase
     * and DatabaseMigrations; unlike the foundation version it never invokes
     * truncateDatabaseTables(), so DatabaseTruncation-based classes would
     * skip their one-time migrate:fresh entirely. Restore the dispatch here.
     */
    protected function setUpDatabaseTraits(array $uses): void
    {
        parent::setUpDatabaseTraits($uses);

        if (isset($uses[DatabaseTruncation::class])) {
            $this->truncateDatabaseTables();
        }
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
        $app['config']->set('database.default', 'clickhouse');

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
            'database' => ':memory:',
        ]);
    }
}

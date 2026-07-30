<?php

namespace ClickHouse\Tests\Hypervel\Feature\Hybrid;

use ClickHouse\Hypervel\Testing\DatabaseTruncation;
use ClickHouse\Hypervel\Testing\RefreshDatabase;
use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use Hypervel\Support\Facades\DB;

use function Hypervel\Testbench\load_migration_paths;

/**
 * The recommended hybrid: the package's RefreshDatabase rolls `:memory:`
 * SQLite back per test while the package's DatabaseTruncation truncates
 * ClickHouse per test — the same cadence on every connection.
 *
 * Lifecycle: RefreshDatabase owns the one-time migrate:fresh — preceded by
 * the pre-wipe of every connection the class works with, derived as the
 * union of $connectionsToTransact and $connectionsToTruncate — and sets
 * RefreshDatabaseState::$migrated, so DatabaseTruncation's own first-run
 * branch and pre-wipe short-circuit, and it only ever truncates.
 */
class SqliteRefreshWithClickHouseTruncationTest extends TestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;
    use ResetsRefreshDatabaseState;

    protected array $connectionsToTransact = ['sqlite'];

    protected array $connectionsToTruncate = ['clickhouse'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'sqlite');
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            __DIR__.'/../database/migrations',
            __DIR__.'/../database/migrations/clickhouse',
        ]);
    }

    public function testRound1InsertsIntoBothConnections(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'sqlite-a']);
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'ch-a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testRound2BothConnectionsAreClean(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(0, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

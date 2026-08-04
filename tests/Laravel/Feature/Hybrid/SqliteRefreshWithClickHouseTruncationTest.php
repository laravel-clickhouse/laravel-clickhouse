<?php

namespace ClickHouse\Tests\Laravel\Feature\Hybrid;

use ClickHouse\Laravel\Testing\DatabaseTruncation;
use ClickHouse\Laravel\Testing\RefreshDatabase;
use ClickHouse\Tests\Laravel\Feature\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Laravel\Feature\TestCase;
use Illuminate\Support\Facades\DB;

use function Orchestra\Testbench\load_migration_paths;

/**
 * The recommended hybrid: the package's RefreshDatabase rolls bare
 * `:memory:` SQLite back per test (the framework preserves the in-memory
 * PDO between tests), while the package's DatabaseTruncation truncates
 * ClickHouse per test — the same cadence on every connection with no
 * shared-cache URI or keepalive PDO needed on the SQLite side.
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

    /** @var array<int, string> */
    protected $connectionsToTransact = ['sqlite'];

    /** @var array<int, string> */
    protected $connectionsToTruncate = ['clickhouse'];

    protected function defaultConnection(): string
    {
        return 'sqlite';
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

<?php

namespace ClickHouse\Tests\Feature\Hybrid;

use ClickHouse\Laravel\Testing\DatabaseTruncation;
use ClickHouse\Tests\Feature\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Feature\Concerns\UsesSharedSqliteDatabase;
use ClickHouse\Tests\Feature\SqliteWithClickHouseTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid isolation: the framework's DatabaseTransactions rolls SQLite back
 * per test while the package's DatabaseTruncation truncates ClickHouse per
 * test — both connections reset with the same cadence, without the per-test
 * migrate:fresh cost of DatabaseMigrations.
 *
 * The stacking order is what makes this sound: Testbench runs the truncation
 * setup (pre-wipe + one-time migrate:fresh building both schemas) before
 * DatabaseTransactions opens its transaction, and DatabaseTransactions never
 * runs migrations itself, so the two traits' lifecycles cannot collide.
 *
 * $connectionsToTruncate deliberately excludes sqlite: its per-test reset is
 * the rollback, and truncating it as well would only add redundant work. The
 * shared-database concern is still required — DatabaseTransactions preserves
 * no in-memory PDO (that machinery is RefreshDatabase-only), so the schema
 * built by the one-time migrate:fresh must survive reconnects on its own.
 */
class SqliteTransactionsWithClickHouseTruncationTest extends SqliteWithClickHouseTestCase
{
    use DatabaseTransactions;
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;
    use UsesSharedSqliteDatabase;

    /** @var array<int, string> */
    protected $connectionsToTransact = ['sqlite'];

    /** @var array<int, string> */
    protected $connectionsToTruncate = ['clickhouse'];

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

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\Hybrid;

use ClickHouse\Tests\Hypervel\Feature\Testing\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\Testing\Concerns\UsesSharedSqliteDatabase;
use ClickHouse\Tests\Hypervel\Feature\Testing\SqliteWithClickHouseTestCase;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;

/**
 * Use transactions for SQLite and truncation for ClickHouse so both
 * connections reset after every test without rebuilding the schema.
 *
 * DatabaseTransactions does not run migrations, so DatabaseTruncation owns
 * the initial migrate:fresh for both connections. Each migration still runs
 * against the connection it declares.
 *
 * SQLite is not truncated because its transaction supplies isolation. The
 * shared database concern keeps its in-memory schema alive across pool
 * flushes because DatabaseTransactions does not preserve the PDO.
 */
class SqliteTransactionsWithClickHouseTruncationTest extends SqliteWithClickHouseTestCase
{
    use DatabaseTransactions;
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;
    use UsesSharedSqliteDatabase;

    protected array $connectionsToTransact = ['sqlite'];

    protected array $connectionsToTruncate = ['clickhouse'];

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

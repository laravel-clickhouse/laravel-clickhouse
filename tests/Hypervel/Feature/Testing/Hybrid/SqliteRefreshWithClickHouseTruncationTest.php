<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\Hybrid;

use ClickHouse\Tests\Hypervel\Feature\Testing\SqliteWithClickHouseTestCase;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;

/**
 * Use transactions for SQLite and truncation for ClickHouse so both
 * connections reset after every test without rebuilding the schema.
 *
 * RefreshDatabase retains the bare in-memory SQLite connection while
 * DatabaseTruncation independently clears ClickHouse between tests.
 */
class SqliteRefreshWithClickHouseTruncationTest extends SqliteWithClickHouseTestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;

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

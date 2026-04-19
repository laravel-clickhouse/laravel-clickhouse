<?php

namespace ClickHouse\Tests\Testbench\RefreshDatabase;

use ClickHouse\Tests\Testbench\SqliteWithClickHouseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Combined scenario: SQLite as the default connection, ClickHouse as a
 * secondary one. RefreshDatabase wraps only SQLite (see
 * SqliteWithClickHouseTestCase::$connectionsToTransact); ClickHouse is left
 * out because its Connection throws LogicException on beginTransaction.
 *
 * SQLite still rolls back per test. ClickHouse data must be cleaned via
 * DatabaseTruncation or DatabaseMigrations if isolation matters.
 */
class SqliteWithClickHouseTest extends SqliteWithClickHouseTestCase
{
    use RefreshDatabase;

    public function testSqliteInsertAndCount(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'sqlite-a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
    }

    public function testSqliteIsRolledBackBetweenTests(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
    }

    public function testClickhouseQueriesStillWorkAlongsideRefreshDatabase(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'ch-a']);

        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

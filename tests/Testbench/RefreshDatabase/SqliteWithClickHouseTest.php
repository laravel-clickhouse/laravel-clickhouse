<?php

namespace ClickHouse\Tests\Testbench\RefreshDatabase;

use ClickHouse\Tests\Testbench\SqliteWithClickHouseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Combined scenario: SQLite as the default connection, ClickHouse as a
 * secondary one.
 *
 * - SQLite transactions behave natively and roll back per test
 * - ClickHouse transactions are no-ops, so data accumulates across tests on
 *   the ClickHouse side (documented caveat)
 *
 * For real isolation on the ClickHouse side, use DatabaseTruncation or
 * DatabaseMigrations instead.
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

    public function testClickhouseInsertUnderRefreshDatabaseDoesNotCrash(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'ch-a']);

        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

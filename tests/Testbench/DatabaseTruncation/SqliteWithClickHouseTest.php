<?php

namespace ClickHouse\Tests\Testbench\DatabaseTruncation;

use ClickHouse\Tests\Testbench\SqliteWithClickHouseTestCase;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * Combined-scenario DatabaseTruncation: tables on both connections are wiped
 * between tests.
 */
class SqliteWithClickHouseTest extends SqliteWithClickHouseTestCase
{
    use DatabaseTruncation;

    public function testRound1InsertsIntoBothConnections(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'sqlite-a']);
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'ch-a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testRound2BothTablesTruncated(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(0, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

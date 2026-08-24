<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\DatabaseTruncation;

use ClickHouse\Tests\Hypervel\Feature\Testing\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\Testing\SqliteWithClickHouseTestCase;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;

/**
 * Combined-scenario DatabaseTruncation: tables on both connections are wiped
 * between tests.
 */
class SqliteWithClickHouseTest extends SqliteWithClickHouseTestCase
{
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;

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

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\DatabaseMigrations;

use ClickHouse\Tests\Hypervel\Feature\Testing\ClickHouseOnlyTestCase;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;

/**
 * DatabaseMigrations rebuilds the schema before every test, so each test
 * starts from an empty ch_events table.
 * Use this strategy for engines TRUNCATE cannot handle (Distributed, View).
 */
class ClickHouseOnlyTest extends ClickHouseOnlyTestCase
{
    use DatabaseMigrations;

    public function testRound1Inserts(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testRound2SeesFreshTable(): void
    {
        $this->assertSame(0, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

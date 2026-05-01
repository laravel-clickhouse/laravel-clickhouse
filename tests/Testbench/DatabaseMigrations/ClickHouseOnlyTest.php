<?php

namespace ClickHouse\Tests\Testbench\DatabaseMigrations;

use ClickHouse\Laravel\Testing\DatabaseMigrations;
use ClickHouse\Tests\Testbench\ClickHouseOnlyTestCase;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end verification of the ClickHouse migration plumbing:
 * - the DatabaseMigrationRepository bound by ClickHouseServiceProvider creates
 *   the migrations table
 * - Schema\Builder::dropAllTables() + DROP TABLE ... SYNC tears it down cleanly
 * - migrate:fresh exercises both halves of the trait lifecycle (up + down)
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

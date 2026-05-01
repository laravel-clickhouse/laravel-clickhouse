<?php

namespace ClickHouse\Tests\Feature\DatabaseTruncation;

use ClickHouse\Laravel\Testing\DatabaseTruncation;
use ClickHouse\Tests\Feature\ClickHouseOnlyTestCase;
use Illuminate\Support\Facades\DB;

/**
 * ClickHouse natively supports TRUNCATE TABLE (Memory / MergeTree family),
 * so the inherited compileTruncate works as-is. This is the recommended way
 * to get real isolation on a ClickHouse connection.
 *
 * Limitation: Distributed, View and a few other engines do not support
 * TRUNCATE; use DatabaseMigrations for those cases.
 */
class ClickHouseOnlyTest extends ClickHouseOnlyTestCase
{
    use DatabaseTruncation;

    public function testRound1Inserts(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testRound2SeesTruncatedTable(): void
    {
        $this->assertSame(0, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

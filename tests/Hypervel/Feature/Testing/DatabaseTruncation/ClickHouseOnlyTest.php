<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\DatabaseTruncation;

use ClickHouse\Tests\Hypervel\Feature\Testing\ClickHouseOnlyTestCase;
use ClickHouse\Tests\Hypervel\Feature\Testing\Concerns\ResetsRefreshDatabaseState;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;

/**
 * ClickHouse natively supports TRUNCATE TABLE (Memory / MergeTree family),
 * so the inherited truncation works as-is. This is the recommended way to
 * get real isolation on a ClickHouse connection.
 */
class ClickHouseOnlyTest extends ClickHouseOnlyTestCase
{
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;

    protected array $connectionsToTruncate = ['clickhouse'];

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

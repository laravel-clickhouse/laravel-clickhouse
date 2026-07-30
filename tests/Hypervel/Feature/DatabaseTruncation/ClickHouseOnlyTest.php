<?php

namespace ClickHouse\Tests\Hypervel\Feature\DatabaseTruncation;

use ClickHouse\Hypervel\Testing\DatabaseTruncation;
use ClickHouse\Tests\Hypervel\Feature\ClickHouseOnlyTestCase;
use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
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

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Coroutine;

use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Hypervel\Parallel;
use ClickHouse\Hypervel\Testing\DatabaseTruncation;
use ClickHouse\Tests\Hypervel\Feature\ClickHouseOnlyTestCase;
use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
use Hypervel\Support\Facades\DB;

/**
 * Hypervel-specific (no Laravel mirror): pins that the Guzzle multi-curl
 * parallel path works inside a Swoole coroutine, where the native curl
 * hook reschedules the blocking multi handle. Tests run inside a
 * coroutine via RunTestsInCoroutine, so every assertion here exercises
 * exactly the environment the docs claim support for.
 */
class ParallelQueryTest extends ClickHouseOnlyTestCase
{
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;

    protected array $connectionsToTruncate = ['clickhouse'];

    public function testSelectParallellyRunsMultipleQueriesInCoroutine(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = DB::connection('clickhouse')->selectParallelly([
            'all' => ['sql' => 'SELECT count(*) AS c FROM ch_events', 'bindings' => []],
            'one' => ['sql' => 'SELECT id FROM ch_events WHERE id = ?', 'bindings' => [2]],
        ]);

        $this->assertEquals(2, $results['all'][0]['c']);
        $this->assertSame(2, $results['one'][0]['id']);
    }

    public function testParallelHelperHydratesQueryBuilders(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = Parallel::get([
            'events' => DB::connection('clickhouse')->table('ch_events')->orderBy('id'),
            'count' => DB::connection('clickhouse')->table('ch_events')->selectRaw('count(*) AS c'),
        ]);

        $this->assertCount(2, $results['events']);
        $this->assertEquals(2, $results['count'][0]['c']);
    }

    public function testParallelQueryErrorSurfacesInCoroutine(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'a']);

        try {
            DB::connection('clickhouse')->selectParallelly([
                'good' => ['sql' => 'SELECT count(*) AS c FROM ch_events', 'bindings' => []],
                'bad' => ['sql' => 'SELECT broken FROM missing_table', 'bindings' => []],
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('bad', $exception->getErrors());
        }
    }
}

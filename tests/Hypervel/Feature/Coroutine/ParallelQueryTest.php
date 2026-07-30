<?php

namespace ClickHouse\Tests\Hypervel\Feature\Coroutine;

use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Hypervel\Parallel;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use Hypervel\Support\Facades\DB;

/**
 * Hypervel-specific (no Laravel mirror): pins that the Guzzle multi-curl
 * parallel path works inside a Swoole coroutine, where the native curl
 * hook reschedules the blocking multi handle. Tests run inside a
 * coroutine via RunTestsInCoroutine, so every assertion here exercises
 * exactly the environment the docs claim support for.
 */
class ParallelQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('coroutine_parallel_test');
        Schema::create('coroutine_parallel_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('coroutine_parallel_test');

        parent::tearDown();
    }

    public function testSelectParallellyRunsMultipleQueriesInCoroutine(): void
    {
        DB::connection('clickhouse')->table('coroutine_parallel_test')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = DB::connection('clickhouse')->selectParallelly([
            'all' => ['sql' => 'SELECT count(*) AS c FROM coroutine_parallel_test', 'bindings' => []],
            'one' => ['sql' => 'SELECT id FROM coroutine_parallel_test WHERE id = ?', 'bindings' => [2]],
        ]);

        $this->assertEquals(2, $results['all'][0]['c']);
        $this->assertSame(2, $results['one'][0]['id']);
    }

    public function testParallelHelperHydratesQueryBuilders(): void
    {
        DB::connection('clickhouse')->table('coroutine_parallel_test')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = Parallel::get([
            'events' => DB::connection('clickhouse')->table('coroutine_parallel_test')->orderBy('id'),
            'count' => DB::connection('clickhouse')->table('coroutine_parallel_test')->selectRaw('count(*) AS c'),
        ]);

        $this->assertCount(2, $results['events']);
        $this->assertEquals(2, $results['count'][0]['c']);
    }

    public function testParallelQueryErrorSurfacesInCoroutine(): void
    {
        DB::connection('clickhouse')->table('coroutine_parallel_test')->insert(['id' => 1, 'name' => 'a']);

        try {
            DB::connection('clickhouse')->selectParallelly([
                'good' => ['sql' => 'SELECT count(*) AS c FROM coroutine_parallel_test', 'bindings' => []],
                'bad' => ['sql' => 'SELECT broken FROM missing_table', 'bindings' => []],
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('bad', $exception->getErrors());
        }
    }
}

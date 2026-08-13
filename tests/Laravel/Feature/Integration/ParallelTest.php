<?php

namespace ClickHouse\Tests\Laravel\Feature\Integration;

use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Facades\Schema;
use ClickHouse\Laravel\Parallel;
use ClickHouse\Tests\Laravel\Feature\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ParallelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('parallel_test');
        Schema::create('parallel_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('parallel_test');

        parent::tearDown();
    }

    public function testSelectParallellyRunsMultipleQueries()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = DB::connection('clickhouse')->selectParallelly([
            'all' => ['sql' => 'SELECT count(*) AS c FROM parallel_test', 'bindings' => []],
            'one' => ['sql' => 'SELECT id FROM parallel_test WHERE id = ?', 'bindings' => [2]],
        ]);

        $this->assertEquals(2, $results['all'][0]['c']);
        $this->assertSame(2, $results['one'][0]['id']);
    }

    public function testParallelHelperHydratesQueryBuilders()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = Parallel::get([
            'events' => $this->table()->orderBy('id'),
            'count' => $this->table()->selectRaw('count(*) AS c'),
        ]);

        $this->assertCount(2, $results['events']);
        $this->assertEquals(2, $results['count'][0]['c']);
    }

    public function testParallelHelperHydratesEloquentBuilders()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $results = Parallel::get([
            'models' => ParallelTestModel::query()->orderBy('id'),
            'rows' => ParallelTestModel::query()->where('id', 2)->toBase(),
        ]);

        $this->assertInstanceOf(EloquentCollection::class, $results['models']);
        $this->assertInstanceOf(ParallelTestModel::class, $results['models']->first());
        $this->assertSame([1, 2], $results['models']->pluck('id')->all());
        $this->assertInstanceOf(Collection::class, $results['rows']);
        $this->assertIsArray($results['rows']->first());
    }

    public function testParallelQueryErrorSurfaces()
    {
        $this->table()->insert(['id' => 1, 'name' => 'a']);

        try {
            DB::connection('clickhouse')->selectParallelly([
                'good' => ['sql' => 'SELECT count(*) AS c FROM parallel_test', 'bindings' => []],
                'bad' => ['sql' => 'SELECT broken FROM missing_table', 'bindings' => []],
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('bad', $exception->getErrors());
        }
    }

    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    protected function table()
    {
        return DB::connection('clickhouse')->table('parallel_test');
    }
}

class ParallelTestModel extends Model
{
    public $timestamps = false;

    protected $connection = 'clickhouse';

    protected $table = 'parallel_test';

    protected $fillable = ['id', 'name'];
}

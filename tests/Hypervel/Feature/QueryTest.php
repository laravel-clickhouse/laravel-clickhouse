<?php

namespace ClickHouse\Tests\Hypervel\Feature;

use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Hypervel\Query\Builder;

class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('hypervel_query_test');
        Schema::create('hypervel_query_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->array('tags', 'String');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('hypervel_query_test');

        parent::tearDown();
    }

    public function testInsertAndSelect()
    {
        $inserted = $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => ['a', 'b']],
            ['id' => 2, 'name' => 'second', 'tags' => ['b']],
        ]);

        $this->assertTrue($inserted);
        $this->assertSame(2, $this->table()->count());
    }

    public function testPrewhere()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => []],
            ['id' => 2, 'name' => 'second', 'tags' => []],
        ]);

        $rows = $this->table()->prewhere('id', '>', 1)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('second', $rows[0]['name']);
    }

    public function testArrayJoin()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => ['a', 'b']],
        ]);

        $rows = $this->table()->select('tag')->arrayJoin(['tag' => 'tags'])->orderBy('tag')->get();

        $this->assertSame(['a', 'b'], $rows->pluck('tag')->all());
    }

    public function testLimitBy()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'duplicated', 'tags' => []],
            ['id' => 2, 'name' => 'duplicated', 'tags' => []],
            ['id' => 3, 'name' => 'unique', 'tags' => []],
        ]);

        $rows = $this->table()->limitBy(1, 'name')->orderBy('id')->get();

        $this->assertCount(2, $rows);
    }

    public function testDelete()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => []],
            ['id' => 2, 'name' => 'second', 'tags' => []],
        ]);

        $this->table()->where('id', 1)->delete(lightweight: true);

        $this->assertSame(1, $this->table()->count());
    }

    protected function table(): Builder
    {
        return $this->app['db']->connection('clickhouse')->table('hypervel_query_test');
    }
}

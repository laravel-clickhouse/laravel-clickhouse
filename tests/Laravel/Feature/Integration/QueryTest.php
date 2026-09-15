<?php

namespace ClickHouse\Tests\Laravel\Feature\Integration;

use ClickHouse\Core\Enums\Format;
use ClickHouse\Laravel\Facades\Schema;
use ClickHouse\Laravel\Query\Builder;
use ClickHouse\Tests\Laravel\Feature\TestCase;

class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('query_test');
        Schema::create('query_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->array('tags', 'String');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('query_test');

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

    public function testInsertWithFormat()
    {
        $connection = $this->app['db']->connection('clickhouse');

        $connection->statement('create table query_format_test (id UInt64, name String, tags Array(String), created_at DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('query_format_test')->insert([
                ['id' => 1, 'name' => 'héllo 👋', 'tags' => ['a', 'b'], 'created_at' => new \DateTimeImmutable('2026-07-29 12:34:56.123456')],
                ['id' => 2, 'name' => 'second', 'tags' => [], 'created_at' => new \DateTimeImmutable('2026-07-29 12:34:56.654321')],
            ], format: Format::JSONEachRow);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [
                    ['id' => 1, 'name' => 'héllo 👋', 'tags' => ['a', 'b'], 'created_at' => '2026-07-29 12:34:56.123456'],
                    ['id' => 2, 'name' => 'second', 'tags' => [], 'created_at' => '2026-07-29 12:34:56.654321'],
                ],
                $connection->table('query_format_test')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table query_format_test');
        }
    }

    protected function defaultConnection(): string
    {
        return 'clickhouse';
    }

    protected function table(): Builder
    {
        return $this->app['db']->connection('clickhouse')->table('query_test');
    }
}

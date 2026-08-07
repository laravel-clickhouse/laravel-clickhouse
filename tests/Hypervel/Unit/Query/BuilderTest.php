<?php

namespace ClickHouse\Tests\Hypervel\Unit\Query;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Query\Builder;
use ClickHouse\Tests\Hypervel\Unit\TestCase;

class BuilderTest extends TestCase
{
    public function testSelectFinal()
    {
        $sql = $this->newBuilder()->from('metrics', final: true)->toSql();

        $this->assertSame('select * from `metrics` final', $sql);
    }

    public function testPrewhere()
    {
        $builder = $this->newBuilder()->from('metrics')->prewhere('date', '=', '2026-01-01');

        $this->assertSame('select * from `metrics` prewhere `date` = ?', $builder->toSql());
        $this->assertSame(['2026-01-01'], $builder->getBindings());
    }

    public function testSampleAndLimitBy()
    {
        $sql = $this->newBuilder()->from('metrics')->sample(0.1)->limitBy(5, 'group')->toSql();

        $this->assertSame('select * from `metrics` sample 0.1 limit 5 by `group`', $sql);
    }

    public function testSettings()
    {
        $builder = $this->newBuilder()->from('metrics')->settings('max_threads', 4);

        $this->assertSame('select * from `metrics` settings `max_threads` = ?', $builder->toSql());
        $this->assertSame([4], $builder->getBindings());
    }

    public function testWhereGlobalIn()
    {
        $builder = $this->newBuilder()->from('metrics')->whereGlobalIn('id', [1, 2, 3]);

        $this->assertSame('select * from `metrics` where `id` global in (?, ?, ?)', $builder->toSql());
        $this->assertSame([1, 2, 3], $builder->getBindings());
    }

    public function testArrayJoin()
    {
        $sql = $this->newBuilder()->from('metrics')->arrayJoin('tags')->toSql();

        $this->assertSame('select * from `metrics` array join `tags`', $sql);
    }

    public function testWhereEmpty()
    {
        $sql = $this->newBuilder()->from('metrics')->whereEmpty('tags')->toSql();

        $this->assertSame('select * from `metrics` where empty(`tags`)', $sql);
    }

    public function testDelete()
    {
        $connection = new Connection('default');
        $grammar = $connection->getQueryGrammar();

        $builder = $connection->query()->from('metrics')->where('id', 1);

        $this->assertSame(
            'alter table `metrics` delete where `id` = ?',
            $grammar->compileDelete($builder)
        );
    }

    public function testLightweightDelete()
    {
        $connection = new Connection('default');
        $grammar = $connection->getQueryGrammar();

        $builder = $connection->query()->from('metrics')->where('id', 1);

        $this->assertSame(
            'delete from `metrics` where `id` = ?',
            $grammar->compileDelete($builder, true)
        );
    }

    protected function newBuilder(): Builder
    {
        return (new Connection('default'))->query();
    }
}

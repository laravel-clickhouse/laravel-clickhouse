<?php

namespace ClickHouse\Tests\Laravel\Eloquent;

use ClickHouse\Laravel\Eloquent\Builder;
use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Query\Builder as BaseBuilder;
use ClickHouse\Laravel\Query\Grammar;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Mockery as m;

class BuilderTest extends TestCase
{
    public function testDelete()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete', [])->andReturn(1);

        $this->assertEquals(1, $builder->delete());
    }

    public function testDeleteWithLightweight()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('delete from `table`', [])->andReturn(1);

        $this->assertEquals(1, $builder->delete(lightweight: true));
    }

    public function testDeleteWithPartition()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete in partition ?', ['partition'])->andReturn(1);

        $this->assertEquals(1, $builder->delete(partition: 'partition'));
    }

    public function testForceDelete()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete', [])->andReturn(1);

        $this->assertEquals(1, $builder->forceDelete());
    }

    public function testForceDeleteWithLightweight()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('delete from `table`', [])->andReturn(1);

        $this->assertEquals(1, $builder->forceDelete(lightweight: true));
    }

    public function testForceDeleteWithPartition()
    {
        $builder = $this->getBuilderForModel();

        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete in partition ?', ['partition'])->andReturn(1);

        $this->assertEquals(1, $builder->forceDelete(partition: 'partition'));
    }

    /**
     * Wrap an Eloquent Builder around a mocked connection resolver so the
     * tests can declare the connection expectations they care about and
     * leave the rest to the defaults.
     */
    private function getBuilderForModel(): Builder
    {
        $query = new BaseBuilder(
            $this->getConnection(),
            $this->getGrammar(Grammar::class),
            m::mock(Processor::class),
        );

        $model = new EloquentBuilderTestStub;
        $model::setConnectionResolver($this->getConnectionResolver());

        $builder = new Builder($query);
        $builder->setModel($model);

        return $builder;
    }

    private function getConnectionResolver(): ConnectionResolverInterface
    {
        $grammar = $this->getGrammar(QueryGrammar::class);
        $processor = new Processor;

        $connection = m::mock(ConnectionInterface::class, [
            'getQueryGrammar' => $grammar,
            'getPostProcessor' => $processor,
            'getDatabaseName' => 'database',
            'getTablePrefix' => '',
        ]);

        $connection->shouldReceive('query')
            ->andReturnUsing(fn () => new BaseBuilder($connection, $grammar, $processor));

        return m::mock(ConnectionResolverInterface::class, ['connection' => $connection]);
    }
}

class EloquentBuilderTestStub extends Model
{
    protected $table = 'table';
}

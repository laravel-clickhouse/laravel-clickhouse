<?php

namespace ClickHouse\Tests\Laravel\Eloquent;

use ClickHouse\Laravel\Eloquent\Builder;
use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Query\Builder as BaseBuilder;
use ClickHouse\Laravel\Query\Grammar;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Processors\Processor;
use Mockery as m;

class BuilderTest extends TestCase
{
    public function testDelete()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete', [])->andReturn(1);

        $result = $builder->delete();
        $this->assertEquals(1, $result);
    }

    public function testDeleteWithLightweight()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('delete from `table`', [])->andReturn(1);

        $result = $builder->delete(lightweight: true);
        $this->assertEquals(1, $result);
    }

    public function testDeleteWithPartition()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete in partition ?', ['partition'])->andReturn(1);

        $result = $builder->delete(partition: 'partition');
        $this->assertEquals(1, $result);
    }

    public function testForceDelete()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete', [])->andReturn(1);

        $result = $builder->forceDelete();
        $this->assertEquals(1, $result);
    }

    public function testForceDeleteWithLightweight()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('delete from `table`', [])->andReturn(1);

        $result = $builder->forceDelete(lightweight: true);
        $this->assertEquals(1, $result);
    }

    public function testForceDeleteWithPartition()
    {
        $query = new BaseBuilder(m::mock(ConnectionInterface::class), new Grammar, m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new EloquentBuilderTestStub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->shouldReceive('getConfig')->once()
            ->with('use_lightweight_delete')->andReturn(false);
        $builder->getConnection()->shouldReceive('delete')->once()
            ->with('alter table `table` delete in partition ?', ['partition'])->andReturn(1);

        $result = $builder->forceDelete(partition: 'partition');
        $this->assertEquals(1, $result);
    }

    private function mockConnectionForModel($model)
    {
        $grammarClass = 'Illuminate\Database\Query\Grammars\Grammar';
        $processorClass = 'Illuminate\Database\Query\Processors\Processor';
        $grammar = new $grammarClass;
        $processor = new $processorClass;
        $connection = m::mock(ConnectionInterface::class, ['getQueryGrammar' => $grammar, 'getPostProcessor' => $processor]);
        $connection->shouldReceive('query')->andReturnUsing(function () use ($connection, $grammar, $processor) {
            return new BaseBuilder($connection, $grammar, $processor);
        });
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $resolver = m::mock(ConnectionResolverInterface::class, ['connection' => $connection]);
        $class = get_class($model);
        $class::setConnectionResolver($resolver);
    }
}

class EloquentBuilderTestStub extends Model
{
    protected $table = 'table';
}

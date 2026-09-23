<?php

namespace ClickHouse\Tests\Hypervel\Unit\Eloquent;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Eloquent\Builder;
use ClickHouse\Hypervel\Eloquent\Model;
use ClickHouse\Hypervel\Query\Builder as BaseBuilder;
use ClickHouse\Tests\Hypervel\Unit\TestCase;
use Hypervel\Database\ConnectionResolverInterface;
use Mockery as m;
use UnitEnum;

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
     * Wrap an Eloquent Builder around a partially mocked connection so the
     * tests can declare the delete expectations they care about while the
     * real grammar compiles the SQL.
     */
    private function getBuilderForModel(): Builder
    {
        // Proxy partial around a real connection: the framework's typed
        // properties (grammar, processor) are initialised by the real
        // constructor, while delete/getConfig expectations are intercepted.
        $connection = m::mock(new Connection('default'));

        $query = new BaseBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
        );

        $model = new EloquentBuilderTestStub;
        $model::setConnectionResolver(
            m::mock(ConnectionResolverInterface::class, ['connection' => $connection])
        );

        $builder = new Builder($query);
        $builder->setModel($model);

        return $builder;
    }
}

class EloquentBuilderTestStub extends Model
{
    protected ?string $table = 'table';

    protected UnitEnum|string|null $connection = 'default';
}

<?php

namespace ClickHouse\Tests\Unit\Laravel\Schema;

use ClickHouse\Laravel\Schema\Blueprint;
use ClickHouse\Laravel\Schema\Builder;
use ClickHouse\Tests\Unit\TestCase;
use ReflectionMethod;

class BuilderTest extends TestCase
{
    public function testCreateBlueprintDispatchesToCustomResolverWhenSet()
    {
        $connection = $this->getConnection();
        $builder = new Builder($connection);
        $expected = new Blueprint($connection, 'users');

        $captured = null;
        $builder->blueprintResolver(function ($table, $callback, $prefix) use (&$captured, $expected) {
            $captured = compact('table', 'callback', 'prefix');

            return $expected;
        });

        // createBlueprint is protected; reach it through reflection to
        // exercise the resolver branch in isolation.
        $actual = (new ReflectionMethod($builder, 'createBlueprint'))
            ->invoke($builder, 'users', null);

        $this->assertSame($expected, $actual);
        $this->assertSame(['table' => 'users', 'callback' => null, 'prefix' => ''], $captured);
    }

    public function testCreateBlueprintFallsBackToContainerWhenNoResolverIsSet()
    {
        $builder = new Builder($this->getConnection());

        $actual = (new ReflectionMethod($builder, 'createBlueprint'))
            ->invoke($builder, 'users', null);

        $this->assertInstanceOf(Blueprint::class, $actual);
        $this->assertSame('users', $actual->getTable());
    }

    public function testDropSyncCompilesDropTableWithSyncKeyword()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE users SYNC')
            ->andReturnTrue();

        (new Builder($connection))->dropSync('users');
    }

    public function testDropIfExistsSyncCompilesDropTableIfExistsWithSyncKeyword()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE IF EXISTS users SYNC')
            ->andReturnTrue();

        (new Builder($connection))->dropIfExistsSync('users');
    }
}

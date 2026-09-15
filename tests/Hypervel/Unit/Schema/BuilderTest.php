<?php

namespace ClickHouse\Tests\Hypervel\Unit\Schema;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Schema\Blueprint;
use ClickHouse\Hypervel\Schema\Builder;
use ClickHouse\Tests\Hypervel\Unit\TestCase;
use Mockery as m;
use ReflectionMethod;

class BuilderTest extends TestCase
{
    public function testCreateBlueprintDispatchesToCustomResolverWhenSet()
    {
        $connection = new Connection('default');
        $connection->useDefaultSchemaGrammar();
        $builder = new Builder($connection);
        $expected = new Blueprint($connection, 'users');

        // Hypervel's resolver signature is (connection, table, callback) —
        // the framework passes the connection where Laravel passes a prefix.
        $captured = null;
        $builder->blueprintResolver(function ($connection, $table, $callback) use (&$captured, $expected) {
            $captured = compact('table', 'callback');

            return $expected;
        });

        // createBlueprint is protected; reach it through reflection to
        // exercise the resolver branch in isolation.
        $actual = (new ReflectionMethod($builder, 'createBlueprint'))
            ->invoke($builder, 'users', null);

        $this->assertSame($expected, $actual);
        $this->assertSame(['table' => 'users', 'callback' => null], $captured);
    }

    public function testCreateBlueprintFallsBackToContainerWhenNoResolverIsSet()
    {
        $connection = new Connection('default');
        $connection->useDefaultSchemaGrammar();
        $builder = new Builder($connection);

        $actual = (new ReflectionMethod($builder, 'createBlueprint'))
            ->invoke($builder, 'users', null);

        $this->assertInstanceOf(Blueprint::class, $actual);
        $this->assertSame('users', $actual->getTable());
    }

    public function testDropSyncCompilesDropTableWithSyncKeyword()
    {
        $connection = m::mock(new Connection('default'));
        $connection->useDefaultSchemaGrammar();
        $connection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE users SYNC')
            ->andReturnTrue();

        (new Builder($connection))->dropSync('users');
    }

    public function testDropIfExistsSyncCompilesDropTableIfExistsWithSyncKeyword()
    {
        $connection = m::mock(new Connection('default'));
        $connection->useDefaultSchemaGrammar();
        $connection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE IF EXISTS users SYNC')
            ->andReturnTrue();

        (new Builder($connection))->dropIfExistsSync('users');
    }
}

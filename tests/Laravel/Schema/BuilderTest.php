<?php

namespace ClickHouse\Tests\Laravel\Schema;

use ClickHouse\Laravel\Schema\Blueprint;
use ClickHouse\Laravel\Schema\Builder;
use ClickHouse\Tests\TestCase;
use ReflectionMethod;

class BuilderTest extends TestCase
{
    public function testCreateBlueprintDispatchesToCustomResolverWhenSet()
    {
        $builder = new Builder($this->connection());
        $expected = $this->blueprint('users');

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
        $builder = new Builder($this->connection());

        $actual = (new ReflectionMethod($builder, 'createBlueprint'))
            ->invoke($builder, 'users', null);

        $this->assertInstanceOf(Blueprint::class, $actual);
        $this->assertSame('users', $actual->getTable());
    }
}

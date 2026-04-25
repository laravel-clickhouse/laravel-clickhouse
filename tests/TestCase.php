<?php

namespace ClickHouse\Tests;

use Carbon\Carbon;
use ClickHouse\Laravel\Schema\Grammar as ClickHouseSchemaGrammar;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow(null);

        m::close();
    }

    /**
     * @template TMock of object
     *
     * @param  class-string<TMock>  $class
     * @param  Closure(LegacyMockInterface&MockInterface&TMock $mock): void  $callback
     * @return LegacyMockInterface&MockInterface&TMock
     */
    protected function mock(string $class, ?Closure $callback = null)
    {
        $mock = m::mock($class);

        if ($callback) {
            $callback($mock);
        }

        return $mock;
    }

    /**
     * Build a fresh Connection mock pre-wired with stubs for the schema- and
     * grammar-compilation paths Laravel 12+ exercises internally. Every call
     * returns a new instance so tests stay isolated.
     */
    protected function getConnection(): Connection
    {
        $state = new class
        {
            public string $prefix = '';
        };

        $mock = m::mock(Connection::class);

        $mock->shouldReceive('getSchemaGrammar')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn () => $this->getGrammar(ClickHouseSchemaGrammar::class, $mock));

        $mock->shouldReceive('getTablePrefix')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn () => $state->prefix);

        $mock->shouldReceive('setTablePrefix')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn ($value) => $state->prefix = $value);

        $mock->shouldReceive('getSchemaBuilder')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn () => new SchemaBuilder($mock))
            ->byDefault();

        $mock->shouldReceive('getConfig')
            ->zeroOrMoreTimes()
            ->andReturnNull()
            ->byDefault();

        return $mock;
    }

    /**
     * Instantiate a grammar bound to the given connection. Laravel 11's
     * Grammar has no explicit constructor — PHP silently swallows the
     * argument — so the connection is then set via setConnection() when
     * that API is available.
     *
     * @template TGrammar of object
     *
     * @param  class-string<TGrammar>  $class
     * @return TGrammar
     */
    protected function getGrammar(string $class, ?Connection $connection = null): object
    {
        $connection ??= $this->getConnection();
        $grammar = new $class($connection);

        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($connection); // @phpstan-ignore method.notFound
        }

        return $grammar;
    }
}

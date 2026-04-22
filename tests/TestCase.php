<?php

namespace ClickHouse\Tests;

use Carbon\Carbon;
use ClickHouse\Laravel\Schema\Blueprint;
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

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow(null);
        $this->connection = null;

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
     * The test-scoped Connection mock. Pre-wired with stubs for the schema-
     * and grammar-compilation paths Laravel 12+ exercises internally, so
     * individual tests can layer their own expectations on top.
     */
    protected function connection(): Connection
    {
        return $this->connection ??= $this->buildConnectionMock();
    }

    /**
     * Instantiate a grammar bound to the given (or shared) connection.
     * Laravel 11's Grammar has no explicit constructor — PHP silently
     * swallows the argument — so the connection is then set via
     * setConnection() when that API is available.
     *
     * @template TGrammar of object
     *
     * @param  class-string<TGrammar>  $class
     * @return TGrammar
     */
    protected function grammar(string $class, ?Connection $connection = null): object
    {
        $connection ??= $this->connection();
        $grammar = new $class($connection);

        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($connection); // @phpstan-ignore method.notFound
        }

        return $grammar;
    }

    /**
     * Instantiate the package's Blueprint bound to the shared connection.
     * Accepts an explicit connection only when a test needs to isolate it.
     */
    protected function blueprint(string $table, ?Closure $callback = null, ?Connection $connection = null): Blueprint
    {
        return new Blueprint($connection ?? $this->connection(), $table, $callback);
    }

    private function buildConnectionMock(): Connection
    {
        $state = new class
        {
            public string $prefix = '';
        };

        $mock = m::mock(Connection::class);

        $mock->shouldReceive('getSchemaGrammar')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn () => $this->grammar(ClickHouseSchemaGrammar::class, $mock));

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
}

<?php

namespace ClickHouse\Tests\Core\Unit;

use Carbon\Carbon;
use Closure;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionProperty;

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
     * Read a transport's inner HTTP client off its protected property —
     * the only way to observe what the factory wired in.
     */
    protected function innerClient(object $transport): mixed
    {
        return (new ReflectionProperty($transport::class, 'client'))->getValue($transport);
    }
}

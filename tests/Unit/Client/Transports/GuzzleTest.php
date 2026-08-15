<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Curl;
use ClickHouse\Client\Transports\Guzzle;
use ClickHouse\Tests\Unit\TestCase;
use ReflectionMethod;

class GuzzleTest extends TestCase
{
    public function testSessionParametersAreAddedToGuzzleUri(): void
    {
        $transport = new Guzzle(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            sessionId: 'session-id',
            sessionTimeout: 120,
        );

        $method = new ReflectionMethod($transport, 'buildRequestUri');

        $this->assertSame(
            'http://localhost:8123/?database=default&default_format=JSON&session_id=session-id&session_timeout=120',
            $method->invoke($transport),
        );
    }

    public function testSessionParametersAreAddedToCurlSettings(): void
    {
        $transport = new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            sessionId: 'session-id',
            sessionTimeout: 120,
        );

        $method = new ReflectionMethod($transport, 'querySettings');

        $this->assertSame([
            'default_format' => 'JSON',
            'session_id' => 'session-id',
            'session_timeout' => 120,
        ], $method->invoke($transport));
    }
}

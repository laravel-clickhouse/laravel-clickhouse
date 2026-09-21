<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Guzzle;
use ClickHouse\Tests\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class GuzzleTest extends TestCase
{
    public function testSessionParametersAreAddedToRequestUri(): void
    {
        $history = [];

        $transport = $this->getTransport($history, sessionId: 'session-id', sessionTimeout: 120);

        $transport->execute('SELECT 1');

        $this->assertSame(
            'http://localhost:8123/?database=default&default_format=JSON&session_id=session-id&session_timeout=120',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testRequestUriOmitsSessionParametersWithoutSession(): void
    {
        $history = [];

        $transport = $this->getTransport($history);

        $transport->execute('SELECT 1');

        $this->assertSame(
            'http://localhost:8123/?database=default&default_format=JSON',
            (string) $history[0]['request']->getUri(),
        );
    }

    /**
     * @param  array<int, array{request: RequestInterface}>  $history
     */
    protected function getTransport(array &$history, ?string $sessionId = null, ?int $sessionTimeout = null): Guzzle
    {
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"data": []}'),
        ]));
        $handler->push(Middleware::history($history));

        return new Guzzle(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            client: new Client(['handler' => $handler]),
            sessionId: $sessionId,
            sessionTimeout: $sessionTimeout,
        );
    }
}

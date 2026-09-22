<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Guzzle;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Exceptions\QueryException;
use ClickHouse\Tests\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

class GuzzleTest extends TestCase
{
    protected const URI = 'http://localhost:8123/';

    protected const CLICKHOUSE_ERROR = "Code: 60. DB::Exception: Unknown table expression identifier 'missing_table' in scope SELECT broken FROM missing_table. (UNKNOWN_TABLE) (version 26.3.1.1)";

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

    public function testErrorResponseBodyIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->getTransport($history, responses: [
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'exception' => self::CLICKHOUSE_ERROR,
            ])),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse query error: '.self::CLICKHOUSE_ERROR);

        $transport->execute('SELECT broken FROM missing_table');
    }

    public function testParallelErrorResponseBodyIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->getTransport($history, responses: [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'exception' => self::CLICKHOUSE_ERROR,
            ])),
        ]);

        try {
            $transport->executeParallelly([
                'good' => 'SELECT 1 as first',
                'bad' => 'SELECT broken FROM missing_table',
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('good', $exception->getResponses());
            $this->assertEquals([['first' => 1]], $exception->getResponses()['good']->getRecords());

            $this->assertArrayHasKey('bad', $exception->getErrors());
            $this->assertSame(
                'ClickHouse query error: '.self::CLICKHOUSE_ERROR,
                $exception->getErrors()['bad']->getMessage(),
            );
        }
    }

    public function testRequestFailureWithoutAResponseSurfacesAsQueryException(): void
    {
        $history = [];

        // Guzzle 8's RequestException has no getResponse() at all, so
        // reaching for it here used to raise "Call to undefined method".
        $transport = $this->getTransport($history, responses: [
            new RequestException('Error completing request', new Request('POST', self::URI)),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse request failed: Error completing request');

        $transport->execute('SELECT 1');
    }

    public function testParallelRequestFailureWithoutAResponseKeepsPartialResults(): void
    {
        $history = [];

        // Same, but in the pool, where an Error would also discard 'good'.
        $transport = $this->getTransport($history, responses: [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            new RequestException('Error completing request', new Request('POST', self::URI)),
        ]);

        try {
            $transport->executeParallelly([
                'good' => 'SELECT 1 as first',
                'bad' => 'SELECT 2 as second',
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('good', $exception->getResponses());
            $this->assertEquals([['first' => 1]], $exception->getResponses()['good']->getRecords());

            $this->assertArrayHasKey('bad', $exception->getErrors());
            $this->assertSame(
                'ClickHouse request failed: Error completing request',
                $exception->getErrors()['bad']->getMessage(),
            );
        }
    }

    /**
     * @param  array<int, array{request: RequestInterface}>  $history
     * @param  array<int, Response|Throwable>  $responses
     */
    protected function getTransport(
        array &$history,
        ?string $sessionId = null,
        ?int $sessionTimeout = null,
        ?array $responses = null,
    ): Guzzle {
        $handler = HandlerStack::create(new MockHandler($responses ?? [
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

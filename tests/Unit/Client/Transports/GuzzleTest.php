<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Guzzle;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Exceptions\QueryException;
use ClickHouse\Tests\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTransferException;
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

    protected const CLICKHOUSE_STREAM_ERROR = 'Code: 395. DB::Exception: Value passed to \'throwIf\' function is non-zero: while executing \'FUNCTION throwIf(equals(number, 1)) UInt8\'. (FUNCTION_THROW_IF_VALUE_IS_NON_ZERO) (version 26.3.1.1)';

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

    public function testErrorInAbortedStreamIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->getTransport($history, responses: [
            $this->createAbortedStreamException(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse query error: ');
        $this->expectExceptionMessage(self::CLICKHOUSE_STREAM_ERROR);

        $transport->execute('SELECT throwIf(number = 1) FROM numbers(2)');
    }

    public function testParallelErrorInAbortedStreamIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->getTransport($history, responses: [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            $this->createAbortedStreamException(),
        ]);

        try {
            $transport->executeParallelly([
                'good' => 'SELECT 1 as first',
                'bad' => 'SELECT throwIf(number = 1) FROM numbers(2)',
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('good', $exception->getResponses());
            $this->assertEquals([['first' => 1]], $exception->getResponses()['good']->getRecords());

            $this->assertArrayHasKey('bad', $exception->getErrors());
            $this->assertStringContainsString(
                self::CLICKHOUSE_STREAM_ERROR,
                $exception->getErrors()['bad']->getMessage(),
            );
        }
    }

    /**
     * A query that fails after ClickHouse has started streaming a 200
     * response: cURL aborts the transfer (error 18) and Guzzle attaches
     * the partial body, which ends with the DB::Exception text. Guzzle 8
     * raises ResponseTransferException, Guzzle 7 a plain RequestException.
     */
    protected function createAbortedStreamException(): RequestException
    {
        $request = new Request('POST', self::URI);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"meta": [], "data": [{"x": 0},'."\n".self::CLICKHOUSE_STREAM_ERROR,
        );
        $message = 'cURL error 18: transfer closed with outstanding read data remaining';

        return class_exists(ResponseTransferException::class)
            ? new ResponseTransferException($message, $request, $response)
            : new RequestException($message, $request, $response);
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

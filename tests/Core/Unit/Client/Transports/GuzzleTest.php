<?php

namespace ClickHouse\Tests\Core\Unit\Client\Transports;

use ClickHouse\Core\Client\Session;
use ClickHouse\Core\Client\Transports\Guzzle;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Exceptions\QueryException;
use ClickHouse\Tests\Core\Unit\Client\Concerns\InspectsTransportClients;
use ClickHouse\Tests\Core\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use TypeError;

class GuzzleTest extends TestCase
{
    use InspectsTransportClients;

    private const URI = 'http://localhost:8123/';

    private const CLICKHOUSE_ERROR = "Code: 60. DB::Exception: Unknown table expression identifier 'missing_table' in scope SELECT broken FROM missing_table. (UNKNOWN_TABLE) (version 26.3.1.1)";

    private const CLICKHOUSE_STREAM_ERROR = 'Code: 395. DB::Exception: Value passed to \'throwIf\' function is non-zero: while executing \'FUNCTION throwIf(equals(number, 1)) UInt8\'. (FUNCTION_THROW_IF_VALUE_IS_NON_ZERO) (version 26.3.1.1)';

    public function testExecuteDoesNotWrapClickHouseQueryErrors()
    {
        foreach ([
            new Response(200, ['Content-Type' => 'application/json'], '{"exception":"Unknown table"}'),
            new Response(200, ['Content-Type' => 'text/plain'], 'Code: 60. DB::Exception: Unknown table (version 25.8.1.1)'),
        ] as $response) {
            $client = $this->mock(Client::class);
            $client->shouldReceive('send')->once()->andReturn($response);

            try {
                $this->transport(client: $client)->execute('select * from missing');
                $this->fail('Expected the ClickHouse query to fail.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('ClickHouse query error:', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function testExecuteMapsRequestAndConnectionFailures()
    {
        $request = new Request('POST', 'http://localhost');
        $response = new Response(400, ['Content-Type' => 'application/json'], '{"exception":"Unknown table"}');
        $requestFailure = new RequestException('truncated error', $request, $response);
        $client = $this->mock(Client::class);
        $client->shouldReceive('send')->once()->andThrow($requestFailure);

        try {
            $this->transport(client: $client)->execute('select * from missing');
            $this->fail('Expected the ClickHouse request to fail.');
        } catch (QueryException $exception) {
            $this->assertSame('ClickHouse query error: Unknown table', $exception->getMessage());
            $this->assertSame($requestFailure, $exception->getPrevious());
        }

        $connectionFailure = new ConnectException('connection refused', $request);
        $client = $this->mock(Client::class);
        $client->shouldReceive('send')->once()->andThrow($connectionFailure);

        try {
            $this->transport(client: $client)->execute('select 1');
            $this->fail('Expected the ClickHouse connection to fail.');
        } catch (QueryException $exception) {
            $this->assertSame('ClickHouse connection failed: connection refused', $exception->getMessage());
            $this->assertSame($connectionFailure, $exception->getPrevious());
        }
    }

    public function testExecutePropagatesUnexpectedThrowables()
    {
        $failure = new TypeError('programming failure');
        $client = $this->mock(Client::class);
        $client->shouldReceive('send')->once()->andThrow($failure);

        try {
            $this->transport(client: $client)->execute('select 1');
            $this->fail('Expected the programming failure to propagate.');
        } catch (TypeError $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testParallelFulfilledClickHouseErrorsRemainPerQueryFailures()
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '{"exception":"Unknown table"}');
        $client = $this->mock(Client::class);
        $client->shouldReceive('sendAsync')->once()->andReturn(Create::promiseFor($response));

        try {
            $this->transport(client: $client)->executeParallelly(['missing' => 'select * from missing']);
            $this->fail('Expected the parallel ClickHouse query to fail.');
        } catch (ParallelQueryException $exception) {
            $this->assertSame([], $exception->getResponses());
            $this->assertInstanceOf(QueryException::class, $exception->getErrors()['missing']);
            $this->assertNull($exception->getErrors()['missing']->getPrevious());
        }
    }

    public function testParallelRejectedRequestErrorsRemainPerQueryFailures()
    {
        $request = new Request('POST', 'http://localhost');
        $response = new Response(400, ['Content-Type' => 'application/json'], '{"exception":"Unknown table"}');
        $failure = new RequestException('truncated error', $request, $response);
        $client = $this->mock(Client::class);
        $client->shouldReceive('sendAsync')->once()->andReturn(Create::rejectionFor($failure));

        try {
            $this->transport(client: $client)->executeParallelly(['missing' => 'select * from missing']);
            $this->fail('Expected the parallel ClickHouse request to fail.');
        } catch (ParallelQueryException $exception) {
            $this->assertSame([], $exception->getResponses());
            $this->assertSame('ClickHouse query error: Unknown table', $exception->getErrors()['missing']->getMessage());
        }
    }

    public function testParallelRejectedTransportFailuresRemainPerQueryFailures()
    {
        $request = new Request('POST', 'http://localhost');

        foreach ([
            [new RequestException('request failed', $request), 'ClickHouse request failed: request failed'],
            [new ConnectException('connection refused', $request), 'ClickHouse connection failed: connection refused'],
        ] as [$failure, $message]) {
            $client = $this->mock(Client::class);
            $client->shouldReceive('sendAsync')->once()->andReturn(Create::rejectionFor($failure));

            try {
                $this->transport(client: $client)->executeParallelly(['query' => 'select 1']);
                $this->fail('Expected the parallel transport request to fail.');
            } catch (ParallelQueryException $exception) {
                $this->assertSame($message, $exception->getErrors()['query']->getMessage());
                $this->assertSame($failure, $exception->getErrors()['query']->getPrevious());
            }
        }
    }

    public function testParallelFulfilledUnexpectedThrowableRejectsTheAggregate()
    {
        $failure = new RuntimeException('stream failure');
        $stream = $this->mock(StreamInterface::class);
        $stream->shouldReceive('getContents')->once()->andThrow($failure);
        $response = $this->mock(ResponseInterface::class);
        $response->shouldReceive('getHeaderLine')->once()->with('Content-Type')->andReturn('application/json');
        $response->shouldReceive('getBody')->once()->andReturn($stream);
        $client = $this->mock(Client::class);
        $client->shouldReceive('sendAsync')->once()->andReturn(Create::promiseFor($response));

        try {
            $this->transport(client: $client)->executeParallelly(['query' => 'select 1']);
            $this->fail('Expected response parsing to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testParallelRejectedUnexpectedThrowableRejectsTheAggregate()
    {
        $failure = new TypeError('programming failure');
        $client = $this->mock(Client::class);
        $client->shouldReceive('sendAsync')->once()->andReturn(Create::rejectionFor($failure));

        try {
            $this->transport(client: $client)->executeParallelly(['query' => 'select 1']);
            $this->fail('Expected the programming failure to propagate.');
        } catch (TypeError $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testGuzzleOptionsConfigureTheDefaultClient()
    {
        $transport = $this->transport(guzzleOptions: ['connect_timeout' => 9]);

        $this->assertSame(9, $this->client($transport)->getConfig('connect_timeout'));
    }

    public function testInjectedClientRemainsUnchanged()
    {
        $client = new Client(['connect_timeout' => 9]);
        $transport = $this->transport(guzzleOptions: ['connect_timeout' => 1.25], client: $client);

        $this->assertSame($client, $this->client($transport));
        $this->assertSame(9, $client->getConfig('connect_timeout'));
    }

    public function testSessionParametersAreAddedToRequestUri(): void
    {
        $history = [];

        $this->transport(client: $this->recordingClient($history), session: new Session('session-id', 120))->execute('SELECT 1');

        $this->assertSame(
            'http://localhost:8123/?database=default&default_format=JSON&session_id=session-id&session_timeout=120',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testRequestUriOmitsSessionParametersWithoutSession(): void
    {
        $history = [];

        $this->transport(client: $this->recordingClient($history))->execute('SELECT 1');

        $this->assertSame(
            'http://localhost:8123/?database=default&default_format=JSON',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testErrorResponseBodyIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->transport(client: $this->recordingClient($history, [
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'exception' => self::CLICKHOUSE_ERROR,
            ])),
        ]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse query error: '.self::CLICKHOUSE_ERROR);

        $transport->execute('SELECT broken FROM missing_table');
    }

    public function testParallelErrorResponseBodyIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->transport(client: $this->recordingClient($history, [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'exception' => self::CLICKHOUSE_ERROR,
            ])),
        ]));

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
        $transport = $this->transport(client: $this->recordingClient($history, [
            new RequestException('Error completing request', new Request('POST', self::URI)),
        ]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse request failed: Error completing request');

        $transport->execute('SELECT 1');
    }

    public function testParallelRequestFailureWithoutAResponseKeepsPartialResults(): void
    {
        $history = [];

        // Same, but in the pool, where an Error would also discard 'good'.
        $transport = $this->transport(client: $this->recordingClient($history, [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            new RequestException('Error completing request', new Request('POST', self::URI)),
        ]));

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

        $transport = $this->transport(client: $this->recordingClient($history, [
            $this->createAbortedStreamException(),
        ]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse query error: ');
        $this->expectExceptionMessage(self::CLICKHOUSE_STREAM_ERROR);

        $transport->execute('SELECT throwIf(number = 1) FROM numbers(2)');
    }

    public function testParallelErrorInAbortedStreamIsPreferredOverGuzzlesSummary(): void
    {
        $history = [];

        $transport = $this->transport(client: $this->recordingClient($history, [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": [{"first": 1}]}'),
            $this->createAbortedStreamException(),
        ]));

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

    private function transport(
        array $guzzleOptions = [],
        ?Client $client = null,
        ?Session $session = null,
    ): Guzzle {
        return new Guzzle(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            guzzleOptions: $guzzleOptions,
            client: $client,
            session: $session,
        );
    }

    /**
     * A query that fails after ClickHouse has started streaming a 200
     * response: cURL aborts the transfer (error 18) and Guzzle attaches
     * the partial body, which ends with the DB::Exception text. Guzzle 8
     * raises ResponseTransferException, Guzzle 7 a plain RequestException.
     */
    private function createAbortedStreamException(): RequestException
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
     * A Guzzle client that answers requests with $responses in order
     * (an empty JSON result by default) and records each request into
     * $history.
     *
     * @param  array<int, array{request: RequestInterface}>  $history
     * @param  array<int, Response|Throwable>|null  $responses
     */
    private function recordingClient(array &$history, ?array $responses = null): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses ?? [
            new Response(200, ['Content-Type' => 'application/json'], '{"data": []}'),
        ]));
        $handler->push(Middleware::history($history));

        return new Client(['handler' => $handler]);
    }

    private function client(Guzzle $transport): Client
    {
        return $this->innerClient($transport);
    }
}

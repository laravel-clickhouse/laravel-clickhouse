<?php

namespace ClickHouse\Tests\Core\Unit\Client\Transports;

use ClickHouse\Core\Client\Transports\Guzzle;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Exceptions\QueryException;
use ClickHouse\Tests\Core\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
use TypeError;

class GuzzleTest extends TestCase
{
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

    public function testConfiguredConnectTimeoutOverridesGuzzleOptions()
    {
        $transport = $this->transport(
            guzzleOptions: ['connect_timeout' => 9],
            connectTimeout: 1.25,
        );

        $this->assertSame(1.25, $this->client($transport)->getConfig('connect_timeout'));
    }

    public function testOmittedConnectTimeoutPreservesGuzzleOptions()
    {
        $transport = $this->transport(guzzleOptions: ['connect_timeout' => 9]);

        $this->assertSame(9, $this->client($transport)->getConfig('connect_timeout'));
    }

    public function testInjectedClientRemainsUnchanged()
    {
        $client = new Client(['connect_timeout' => 9]);
        $transport = $this->transport(client: $client, connectTimeout: 1.25);

        $this->assertSame($client, $this->client($transport));
        $this->assertSame(9, $client->getConfig('connect_timeout'));
    }

    public function testSessionParametersAreAddedToRequestUri(): void
    {
        $history = [];

        $this->transport(client: $this->recordingClient($history), sessionId: 'session-id', sessionTimeout: 120)->execute('SELECT 1');

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

    private function transport(
        array $guzzleOptions = [],
        ?Client $client = null,
        ?float $connectTimeout = null,
        ?string $sessionId = null,
        ?int $sessionTimeout = null,
    ): Guzzle {
        return new Guzzle(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            guzzleOptions: $guzzleOptions,
            client: $client,
            connectTimeout: $connectTimeout,
            sessionId: $sessionId,
            sessionTimeout: $sessionTimeout,
        );
    }

    /**
     * A Guzzle client that answers every request with an empty JSON result
     * and records each request into $history.
     *
     * @param  array<int, array{request: RequestInterface}>  $history
     */
    private function recordingClient(array &$history): Client
    {
        $handler = HandlerStack::create(new MockHandler([
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

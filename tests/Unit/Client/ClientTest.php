<?php

namespace ClickHouse\Tests\Unit\Client;

use ClickHouse\Client\Client;
use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Response;
use ClickHouse\Client\Statement;
use ClickHouse\Client\TransportFactory;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Tests\Unit\TestCase;
use Exception;
use LogicException;

class ClientTest extends TestCase
{
    public function testExec()
    {
        $transport = $this->mock(Transport::class);
        $response = $this->mock(Response::class);

        $transport
            ->shouldReceive('execute')
            ->with($query = 'select 1')
            ->once()
            ->andReturn($response);
        $response
            ->shouldReceive('getAffectedRows')
            ->andReturn($affectedRows = 1);

        $client = $this->getClient($transport);

        $this->assertEquals($affectedRows, $client->exec($query));
    }

    public function testPrepare()
    {
        $this->assertInstanceOf(
            Statement::class,
            $this->getClient()->prepare('select 1')
        );
    }

    public function testParallel()
    {
        $statement1 = $this->mock(Statement::class);
        $statement2 = $this->mock(Statement::class);
        $transport = $this->mock(Transport::class);
        $result1 = [1];
        $result2 = [2];

        $statement1
            ->shouldReceive('toRawSql')
            ->withNoArgs()
            ->once()
            ->andReturn($sql1 = 'select 1');
        $statement1
            ->shouldReceive('setResponse')
            ->withArgs(fn ($response) => $response->getRecords() === $result1)
            ->once();
        $statement2
            ->shouldReceive('toRawSql')
            ->withNoArgs()
            ->once()
            ->andReturn($sql2 = 'select 2');
        $statement2
            ->shouldReceive('setResponse')
            ->withArgs(fn ($response) => $response->getRecords() === $result2)
            ->once();
        $transport
            ->shouldReceive('executeParallelly')
            ->with([$sql1, $sql2])
            ->once()
            ->andReturn([
                new Response($sql1, null, $result1),
                new Response($sql2, null, $result2),
            ]);

        $client = $this->getClient($transport);
        $client->parallel([$statement1, $statement2]);
    }

    public function testParallelWithException()
    {
        $statement1 = $this->mock(Statement::class);
        $statement2 = $this->mock(Statement::class);
        $transport = $this->mock(Transport::class);
        $result1 = [1];

        $statement1
            ->shouldReceive('toRawSql')
            ->withNoArgs()
            ->once()
            ->andReturn($sql1 = 'select 1');
        $statement1
            ->shouldReceive('setResponse')
            ->withArgs(fn ($response) => $response->getRecords() === $result1)
            ->once();
        $statement2
            ->shouldReceive('toRawSql')
            ->withNoArgs()
            ->once()
            ->andReturn($sql2 = 'select 2');
        $transport
            ->shouldReceive('executeParallelly')
            ->with([$sql1, $sql2])
            ->once()
            ->andThrow(new ParallelQueryException(
                [0 => new Response($sql1, null, $result1)],
                [1 => $exception2 = new Exception('error')]
            ));

        $client = $this->getClient($transport);

        try {
            $client->parallel([$statement1, $statement2]);
        } catch (ParallelQueryException $e) {
            $this->assertEquals($result1, $e->getResponses()[0]->getRecords());
            $this->assertEquals($exception2, $e->getErrors()[1]);
        }
    }

    public function testGetTransport()
    {
        $transport = $this->mock(Transport::class);
        $client = $this->getClient($transport);

        $this->assertEquals($transport, $client->getTransport());
    }

    public function testSessionIsPassedToTransportFactory()
    {
        $factory = $this->mock(TransportFactory::class);
        $transport = $this->mock(Transport::class);

        $factory->shouldReceive('make')
            ->with('curl', 'session-id', 120)
            ->once()
            ->andReturn($transport);

        $client = new Client(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            transport: 'curl',
            transportFactory: $factory,
        );

        $client->startSession('session-id', 120);

        $this->assertSame($transport, $client->getTransport());
    }

    public function testParallelRejectsActiveSession()
    {
        $client = $this->getClient();
        $client->startSession('session-id', 120);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Parallel queries cannot be executed within a ClickHouse session.');

        $client->parallel([]);
    }

    public function testGetSession()
    {
        $client = $this->getClient();

        $this->assertNull($client->getSession());

        $client->startSession('session-id', 120);

        $this->assertSame(['id' => 'session-id', 'timeout' => 120], $client->getSession());

        $client->endSession();

        $this->assertNull($client->getSession());
    }

    private function getClient(?Transport $transport = null): Client
    {
        $factory = $this->mock(TransportFactory::class);

        if ($transport) {
            $factory->shouldReceive('make')->andReturn($transport);
        }

        return new Client(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            transport: 'curl',
            transportFactory: $factory,
        );
    }
}

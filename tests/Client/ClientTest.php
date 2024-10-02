<?php

namespace SwooleTW\ClickHouse\Tests\Client;

use Exception;
use SwooleTW\ClickHouse\Client\Client;
use SwooleTW\ClickHouse\Client\Contracts\Transport;
use SwooleTW\ClickHouse\Client\Response;
use SwooleTW\ClickHouse\Client\Statement;
use SwooleTW\ClickHouse\Client\TransportFactory;
use SwooleTW\ClickHouse\Exceptions\ParallelQueryException;
use SwooleTW\ClickHouse\Tests\TestCase;

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
            ->andReturn([$result1, $result2]);

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
                [0 => $result1],
                [1 => $exception2 = new Exception('error')]
            ));

        $client = $this->getClient($transport);

        try {
            $client->parallel([$statement1, $statement2]);
        } catch (ParallelQueryException $e) {
            $this->assertEquals($statement1, $e->getResults()[0]);
            $this->assertEquals($exception2, $e->getErrors()[1]);
        }
    }

    public function testGetTransport()
    {
        $transport = $this->mock(Transport::class);
        $client = $this->getClient($transport);

        $this->assertEquals($transport, $client->getTransport());
    }

    public function testQuote()
    {
        $client = $this->getClient();

        $this->assertEquals("'str\\\\ing'", $client->quote('str\ing'));
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

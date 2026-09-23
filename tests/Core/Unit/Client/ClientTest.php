<?php

namespace ClickHouse\Tests\Core\Unit\Client;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Client\Response;
use ClickHouse\Core\Client\Session;
use ClickHouse\Core\Client\Statement;
use ClickHouse\Core\Client\TransportFactory;
use ClickHouse\Core\Client\Transports\Curl;
use ClickHouse\Core\Client\Transports\Guzzle;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Tests\Core\Unit\Client\Concerns\InspectsTransportClients;
use ClickHouse\Tests\Core\Unit\TestCase;
use ClickHouseDB\Client as ClickHouseClient;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use LogicException;

class ClientTest extends TestCase
{
    use InspectsTransportClients;

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
        $statement1->shouldReceive('getSession')->andReturnNull();
        $statement2 = $this->mock(Statement::class);
        $statement2->shouldReceive('getSession')->andReturnNull();
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
        $statement1->shouldReceive('getSession')->andReturnNull();
        $statement2 = $this->mock(Statement::class);
        $statement2->shouldReceive('getSession')->andReturnNull();
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

    public function testInjectedTransportFactoryRemainsAuthoritative()
    {
        $transport = $this->mock(Transport::class);
        $client = $this->getClient($transport, 1.25);

        $this->assertEquals($transport, $client->getTransport());
    }

    public function testTimeoutsReachGuzzleThroughTheDefaultFactory()
    {
        $client = new Client(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            transport: 'guzzle',
            timeout: 30,
            connectTimeout: 1.25,
        );

        $transport = $client->getTransport();
        $this->assertInstanceOf(Guzzle::class, $transport);

        $guzzleClient = $this->innerClient($transport);

        $this->assertInstanceOf(GuzzleClient::class, $guzzleClient);
        $this->assertSame(30.0, $guzzleClient->getConfig('timeout'));
        $this->assertSame(1.25, $guzzleClient->getConfig('connect_timeout'));
    }

    public function testTimeoutsReachCurlThroughTheDefaultFactory()
    {
        $client = new Client(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            transport: 'curl',
            timeout: 30,
            connectTimeout: 1.25,
        );

        $transport = $client->getTransport();
        $this->assertInstanceOf(Curl::class, $transport);

        $clickHouseClient = $this->innerClient($transport);

        $this->assertInstanceOf(ClickHouseClient::class, $clickHouseClient);
        $this->assertSame(30, $clickHouseClient->getTimeout());
        $this->assertSame(1.25, $clickHouseClient->getConnectTimeOut());
    }

    public function testSessionIsPassedToTransportFactory()
    {
        $factory = $this->mock(TransportFactory::class);
        $transport = $this->mock(Transport::class);
        $session = new Session('session-id', 120);

        $factory->shouldReceive('make')
            ->with('curl', $session)
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

        $this->assertSame($transport, $client->getTransport($session));
    }

    public function testPrepareCarriesTheSessionOntoTheStatement()
    {
        $client = $this->getClient();
        $session = new Session('session-id', 120);

        $this->assertSame($session, $client->prepare('select 1', $session)->getSession());
        $this->assertNull($client->prepare('select 1')->getSession());
    }

    public function testParallelRejectsStatementsBoundToASession()
    {
        $client = $this->getClient();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Parallel queries cannot be executed within a ClickHouse session.');

        $client->parallel([$client->prepare('select 1', new Session('session-id', 120))]);
    }

    private function getClient(?Transport $transport = null, ?float $connectTimeout = null): Client
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
            connectTimeout: $connectTimeout,
        );
    }
}

<?php

namespace ClickHouse\Tests\Core\Unit\Client;

use ClickHouse\Core\Client\TransportFactory;
use ClickHouse\Core\Client\Transports\Curl;
use ClickHouse\Core\Client\Transports\Guzzle;
use ClickHouse\Tests\Core\Unit\Client\Concerns\InspectsTransportClients;
use ClickHouse\Tests\Core\Unit\TestCase;
use ClickHouseDB\Client as ClickHouseDBClient;
use InvalidArgumentException;
use ReflectionProperty;

class TransportFactoryTest extends TestCase
{
    use InspectsTransportClients;

    public function testGuzzleTransportReceivesTimeoutOptions(): void
    {
        $transport = $this->getFactory(timeout: 30, connectTimeout: 2.5)->make('guzzle');

        $this->assertInstanceOf(Guzzle::class, $transport);
        $this->assertSame(
            ['timeout' => 30.0, 'connect_timeout' => 2.5],
            (new ReflectionProperty($transport, 'guzzleOptions'))->getValue($transport),
        );
    }

    public function testGuzzleTransportOmitsTimeoutOptionsWhenNotConfigured(): void
    {
        $transport = $this->getFactory()->make('guzzle');

        $this->assertSame([], (new ReflectionProperty($transport, 'guzzleOptions'))->getValue($transport));
    }

    public function testCurlTransportReceivesTimeouts(): void
    {
        $transport = $this->getFactory(timeout: 30, connectTimeout: 2.5)->make('curl');

        $this->assertInstanceOf(Curl::class, $transport);

        $client = $this->innerClient($transport);

        $this->assertInstanceOf(ClickHouseDBClient::class, $client);
        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(2.5, $client->getConnectTimeOut());
    }

    public function testNegativeTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->getFactory(timeout: -1);
    }

    public function testNegativeConnectTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->getFactory(connectTimeout: -1);
    }

    protected function getFactory(?float $timeout = null, ?float $connectTimeout = null): TransportFactory
    {
        return new TransportFactory(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
    }
}

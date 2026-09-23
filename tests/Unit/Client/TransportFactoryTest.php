<?php

namespace ClickHouse\Tests\Unit\Client;

use ClickHouse\Client\TransportFactory;
use ClickHouse\Client\Transports\Curl;
use ClickHouse\Client\Transports\Guzzle;
use ClickHouse\Tests\Unit\TestCase;
use ClickHouseDB\Client as ClickHouseDBClient;
use InvalidArgumentException;
use ReflectionProperty;

class TransportFactoryTest extends TestCase
{
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

        $client = $this->getCurlClient($transport);

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

    protected function getCurlClient(Curl $transport): ClickHouseDBClient
    {
        /** @var ClickHouseDBClient */
        return (new ReflectionProperty($transport, 'client'))->getValue($transport);
    }
}

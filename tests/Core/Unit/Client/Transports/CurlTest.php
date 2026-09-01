<?php

namespace ClickHouse\Tests\Core\Unit\Client\Transports;

use ClickHouse\Core\Client\Transports\Curl;
use ClickHouse\Tests\Core\Unit\TestCase;
use ClickHouseDB\Client;

class CurlTest extends TestCase
{
    public function testConfiguredConnectTimeoutReachesTheDefaultClient()
    {
        $transport = $this->transport(connectTimeout: 1.25);

        $this->assertSame(1.25, $this->client($transport)->getConnectTimeOut());
    }

    public function testOmittedConnectTimeoutPreservesTheClientDefault()
    {
        $expected = $this->newClient()->getConnectTimeOut();
        $transport = $this->transport();

        $this->assertSame($expected, $this->client($transport)->getConnectTimeOut());
    }

    public function testInjectedClientRemainsUnchanged()
    {
        $client = $this->newClient();
        $client->setConnectTimeOut(9.0);
        $transport = $this->transport(client: $client, connectTimeout: 1.25);

        $this->assertSame($client, $this->client($transport));
        $this->assertSame(9.0, $client->getConnectTimeOut());
    }

    private function transport(?Client $client = null, ?float $connectTimeout = null): Curl
    {
        return new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            client: $client,
            connectTimeout: $connectTimeout,
        );
    }

    private function newClient(): Client
    {
        return new Client([
            'host' => 'localhost',
            'port' => 8123,
            'username' => 'default',
            'password' => 'default',
        ]);
    }

    private function client(Curl $transport): Client
    {
        return $this->innerClient($transport);
    }
}

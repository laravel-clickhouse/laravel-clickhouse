<?php

namespace ClickHouse\Tests\Core\Unit\Client\Transports;

use ClickHouse\Core\Client\Transports\Curl;
use ClickHouse\Tests\Core\Unit\Client\Concerns\InspectsTransportClients;
use ClickHouse\Tests\Core\Unit\TestCase;
use ClickHouseDB\Client;

class CurlTest extends TestCase
{
    use InspectsTransportClients;

    public function testTimeoutsAreAppliedToClient(): void
    {
        $client = $this->client($this->transport(timeout: 30, connectTimeout: 2.5));

        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(2.5, $client->getConnectTimeOut());
    }

    public function testSubSecondTimeoutIsRoundedUpInsteadOfBecomingUnlimited(): void
    {
        $this->assertSame(1, $this->client($this->transport(timeout: 0.5))->getTimeout());
    }

    public function testZeroTimeoutDisablesLimit(): void
    {
        $this->assertSame(0, $this->client($this->transport(timeout: 0))->getTimeout());
    }

    public function testLibraryDefaultsAreKeptWhenTimeoutsAreNotConfigured(): void
    {
        $client = $this->client($this->transport());

        $this->assertSame(20, $client->getTimeout());
        $this->assertSame(5.0, $client->getConnectTimeOut());
    }

    public function testInjectedClientRemainsUnchanged(): void
    {
        $client = $this->newClient();
        $client->setConnectTimeOut(9.0);
        $transport = $this->transport(client: $client, connectTimeout: 1.25);

        $this->assertSame($client, $this->client($transport));
        $this->assertSame(9.0, $client->getConnectTimeOut());
    }

    private function transport(?Client $client = null, ?float $timeout = null, ?float $connectTimeout = null): Curl
    {
        return new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            client: $client,
            timeout: $timeout,
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

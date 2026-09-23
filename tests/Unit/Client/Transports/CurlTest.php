<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Curl;
use ClickHouse\Tests\Unit\TestCase;
use ClickHouseDB\Client as ClickHouseDBClient;
use ReflectionProperty;

class CurlTest extends TestCase
{
    public function testTimeoutsAreAppliedToClient(): void
    {
        $client = $this->getClient(timeout: 30, connectTimeout: 2.5);

        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(2.5, $client->getConnectTimeOut());
    }

    public function testSubSecondTimeoutIsRoundedUpInsteadOfBecomingUnlimited(): void
    {
        $this->assertSame(1, $this->getClient(timeout: 0.5)->getTimeout());
    }

    public function testZeroTimeoutDisablesLimit(): void
    {
        $this->assertSame(0, $this->getClient(timeout: 0)->getTimeout());
    }

    public function testLibraryDefaultsAreKeptWhenTimeoutsAreNotConfigured(): void
    {
        $client = $this->getClient();

        $this->assertSame(20, $client->getTimeout());
        $this->assertSame(5.0, $client->getConnectTimeOut());
    }

    protected function getClient(?float $timeout = null, ?float $connectTimeout = null): ClickHouseDBClient
    {
        $transport = new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );

        /** @var ClickHouseDBClient */
        return (new ReflectionProperty($transport, 'client'))->getValue($transport);
    }
}

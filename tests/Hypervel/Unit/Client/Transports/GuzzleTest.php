<?php

namespace ClickHouse\Tests\Hypervel\Unit\Client\Transports;

use ClickHouse\Core\Client\Transports\Guzzle;
use ClickHouse\Tests\Hypervel\Unit\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use Swoole\Coroutine\CanceledException;

class GuzzleTest extends TestCase
{
    public function testParallelCancellationRejectsTheAggregateUnchanged()
    {
        $cancellation = new CanceledException;
        $client = $this->mock(Client::class);
        $client->shouldReceive('sendAsync')->once()->andReturn(Create::rejectionFor($cancellation));
        $transport = new Guzzle(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: 'default',
            client: $client,
        );

        try {
            $transport->executeParallelly(['query' => 'select 1']);
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }
}

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Coroutine;

use ClickHouse\Core\Client\Client;
use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Testing\DatabaseTruncation;
use ClickHouse\Tests\Hypervel\Feature\ClickHouseOnlyTestCase;
use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
use Hypervel\Coroutine\Parallel as CoroutineParallel;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Facades\DB;

/**
 * Hypervel-specific (no Laravel mirror): pins the pooled-connection design
 * decisions the Hypervel bridge is built on — each pooled slot carries its
 * own HTTP client, coroutines run queries concurrently, the pool heartbeat
 * is a no-op for the PDO-less connection, and reconnect() replaces the
 * HTTP client.
 *
 * The pool-level assertions borrow slots straight from PoolFactory: the
 * testing lifecycle swaps in DatabaseConnectionResolver, whose
 * process-global connection cache would otherwise hand every coroutine the
 * same wrapper and hide the pool behaviour under test.
 */
class ConnectionPoolTest extends ClickHouseOnlyTestCase
{
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;

    protected array $connectionsToTruncate = ['clickhouse'];

    public function testConcurrentCoroutinesQueryThroughThePool(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ]);

        $parallel = new CoroutineParallel;

        foreach ([1, 2, 3] as $id) {
            $parallel->add(function () use ($id) {
                $row = DB::connection('clickhouse')
                    ->table('ch_events')
                    ->where('id', $id)
                    ->first();

                return $row['id'];
            }, $id);
        }

        $this->assertSame([1 => 1, 2 => 2, 3 => 3], $parallel->wait());
    }

    public function testEachPooledSlotCarriesItsOwnHttpClient(): void
    {
        $pool = $this->app->get(PoolFactory::class)->getPool('clickhouse');

        $first = $pool->get();
        $second = $pool->get();

        try {
            $firstConnection = $first->getConnection();
            $secondConnection = $second->getConnection();
            assert($firstConnection instanceof Connection && $secondConnection instanceof Connection);

            $this->assertNotSame($firstConnection->getClient(), $secondConnection->getClient());

            // Both slots are live, independently usable connections.
            $this->assertEquals(1, $firstConnection->select('SELECT 1 AS one')[0]['one']);
            $this->assertEquals(2, $secondConnection->select('SELECT 2 AS two')[0]['two']);
        } finally {
            $pool->release($first);
            $pool->release($second);
        }
    }

    public function testReleasedSlotsReturnToTheChannel(): void
    {
        $pool = $this->app->get(PoolFactory::class)->getPool('clickhouse');

        $borrowed = $pool->get();
        $inChannelWhileBorrowed = $pool->getConnectionsInChannel();

        $pool->release($borrowed);

        $this->assertSame($inChannelWhileBorrowed + 1, $pool->getConnectionsInChannel());
    }

    public function testReconnectReplacesTheHttpClient(): void
    {
        $connection = DB::connection('clickhouse');
        assert($connection instanceof Connection);

        $original = $connection->getClient();

        $connection->reconnect();

        $this->assertInstanceOf(Client::class, $connection->getClient());
        $this->assertNotSame($original, $connection->getClient());

        // The fresh client is immediately usable.
        $this->assertEquals(1, $connection->select('SELECT 1 AS one')[0]['one']);
    }

    public function testPingReportsServerHealthOverHttp(): void
    {
        $connection = DB::connection('clickhouse');
        assert($connection instanceof Connection);

        $this->assertTrue($connection->ping());
    }
}

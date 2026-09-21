<?php

namespace ClickHouse\Tests\Hypervel\Feature\Coroutine;

use ClickHouse\Core\Client\Client;
use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use Hypervel\Coroutine\Parallel as CoroutineParallel;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Support\Facades\DB;

/**
 * Hypervel-specific (no Laravel mirror): pins the pooled-connection design
 * decisions the Hypervel bridge is built on — each pooled slot carries its
 * own HTTP client, coroutines run queries concurrently, pool health checks
 * query ClickHouse, and reconnect() replaces the HTTP client.
 *
 * The pool-level assertions borrow slots straight from PoolManager: the
 * testing lifecycle swaps in DatabaseConnectionResolver, whose
 * process-global connection cache would otherwise hand every coroutine the
 * same wrapper and hide the pool behaviour under test.
 */
class ConnectionPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('coroutine_pool_test');
        Schema::create('coroutine_pool_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('coroutine_pool_test');

        parent::tearDown();
    }

    public function testConcurrentCoroutinesQueryThroughThePool(): void
    {
        DB::connection('clickhouse')->table('coroutine_pool_test')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ]);

        $parallel = new CoroutineParallel;

        foreach ([1, 2, 3] as $id) {
            $parallel->add(function () use ($id) {
                $row = DB::connection('clickhouse')
                    ->table('coroutine_pool_test')
                    ->where('id', $id)
                    ->first();

                return $row['id'];
            }, $id);
        }

        $this->assertSame([1 => 1, 2 => 2, 3 => 3], $parallel->wait());
    }

    public function testEachPooledSlotCarriesItsOwnHttpClient(): void
    {
        $pool = $this->app->get(PoolManager::class)->pool('clickhouse');

        $first = $pool->borrow();
        $second = $pool->borrow();

        try {
            $firstConnection = $first->getConnection();
            $secondConnection = $second->getConnection();
            $this->assertInstanceOf(Connection::class, $firstConnection);
            $this->assertInstanceOf(Connection::class, $secondConnection);

            $this->assertNotSame($firstConnection->getClient(), $secondConnection->getClient());

            // Both slots are live, independently usable connections.
            $this->assertEquals(1, $firstConnection->select('SELECT 1 AS one')[0]['one']);
            $this->assertEquals(2, $secondConnection->select('SELECT 2 AS two')[0]['two']);
        } finally {
            $pool->release($first);
            $pool->release($second);
        }
    }

    public function testReleasedSlotsReturnToTheIdleSet(): void
    {
        $pool = $this->app->get(PoolManager::class)->pool('clickhouse');

        $borrowed = $pool->borrow();
        $inChannelWhileBorrowed = $pool->getIdleCount();

        $pool->release($borrowed);

        $this->assertSame($inChannelWhileBorrowed + 1, $pool->getIdleCount());
    }

    public function testReconnectReplacesTheHttpClient(): void
    {
        $connection = DB::connection('clickhouse');
        $this->assertInstanceOf(Connection::class, $connection);

        $original = $connection->getClient();
        $reconnected = DB::reconnect('clickhouse');

        $this->assertSame($connection, $reconnected);
        $this->assertInstanceOf(Client::class, $connection->getClient());
        $this->assertNotSame($original, $connection->getClient());

        // The fresh client is immediately usable.
        $this->assertEquals(1, $connection->select('SELECT 1 AS one')[0]['one']);
    }

    public function testPingReportsServerHealthOverHttp(): void
    {
        $connection = DB::connection('clickhouse');
        $this->assertInstanceOf(Connection::class, $connection);

        $this->assertTrue($connection->ping());
    }

    public function testPoolHeartbeatProbesClickHouse(): void
    {
        $pool = $this->app->get(PoolManager::class)->pool('clickhouse');
        $pooledConnection = $pool->borrow();

        try {
            $this->assertTrue($pooledConnection->ping(1.0));
        } finally {
            $pooledConnection->release();
        }
    }

    public function testPoolReleaseRetainsTheClientAndRestoresConfiguredMetadata(): void
    {
        $pool = $this->app->get(PoolManager::class)->pool('clickhouse');
        $pooledConnection = $pool->borrow();
        $connection = $pooledConnection->getConnection();
        $client = $connection->getClient();
        $connection->setDatabaseName('temporary');
        $connection->setTablePrefix('temporary_');
        $connection->enableQueryLog();
        $connection->recordsHaveBeenModified();

        $pooledConnection->release();

        $reusedPooledConnection = $pool->borrow();

        try {
            $this->assertSame($pooledConnection, $reusedPooledConnection);

            $reusedConnection = $reusedPooledConnection->getConnection();

            $this->assertSame($connection, $reusedConnection);
            $this->assertSame($client, $reusedConnection->getClient());
            $this->assertSame('default', $reusedConnection->getDatabaseName());
            $this->assertSame('', $reusedConnection->getTablePrefix());
            $this->assertFalse($reusedConnection->logging());
            $this->assertFalse($reusedConnection->hasModifiedRecords());
        } finally {
            $reusedPooledConnection->release();
        }
    }
}

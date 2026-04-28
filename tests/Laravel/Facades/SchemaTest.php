<?php

namespace ClickHouse\Tests\Laravel\Facades;

use ArrayAccess;
use ClickHouse\Laravel\Facades\Schema;
use ClickHouse\Laravel\Schema\Builder;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use Mockery as m;

class SchemaTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testConnectionResolvesToClickHouseBuilder()
    {
        $connection = $this->getConnection();
        $expectedBuilder = new Builder($connection);
        $connection->shouldReceive('getSchemaBuilder')
            ->once()
            ->andReturn($expectedBuilder);

        $manager = m::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')
            ->once()
            ->with('analytics')
            ->andReturn($connection);

        $app = m::mock(ArrayAccess::class);
        $app->shouldReceive('offsetGet')
            ->with('db')
            ->andReturn($manager);

        Facade::setFacadeApplication($app);

        $this->assertSame($expectedBuilder, Schema::connection('analytics'));
    }

    public function testStaticDropSyncProxiesThroughDefaultBinding()
    {
        $clickHouseConnection = $this->getConnection();
        $clickHouseConnection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE IF EXISTS users SYNC')
            ->andReturnTrue();

        $app = m::mock(ArrayAccess::class);
        $app->shouldReceive('offsetGet')
            ->with('db.schema')
            ->andReturn(new Builder($clickHouseConnection));

        Facade::setFacadeApplication($app);

        Schema::dropIfExistsSync('users');
    }
}

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Tests\Hypervel\Feature\TestCase;

class ConnectionTest extends TestCase
{
    public function testResolvesClickHouseConnectionFromManager()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('clickhouse', $connection->getName());
        $this->assertSame('clickhouse', $connection->getDriverName());
        $this->assertSame('default', $connection->getDatabaseName());
    }

    public function testSelect()
    {
        $rows = $this->app->make('db')->connection('clickhouse')->select('SELECT 1 AS one');

        $this->assertSame([['one' => 1]], $rows);
    }

    public function testSelectWithBindings()
    {
        $rows = $this->app->make('db')->connection('clickhouse')->select('SELECT ? AS value', ['clickhouse']);

        $this->assertSame([['value' => 'clickhouse']], $rows);
    }
}

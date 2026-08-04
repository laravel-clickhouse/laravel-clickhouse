<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Tests\Hypervel\Feature\TestCase;

class ConnectionTest extends TestCase
{
    public function testResolvesClickHouseConnectionFromManager()
    {
        $connection = $this->app['db']->connection('clickhouse');

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testSelect()
    {
        $rows = $this->app['db']->connection('clickhouse')->select('SELECT 1 AS one');

        $this->assertSame([['one' => 1]], $rows);
    }

    public function testSelectWithBindings()
    {
        $rows = $this->app['db']->connection('clickhouse')->select('SELECT ? AS value', ['clickhouse']);

        $this->assertSame([['value' => 'clickhouse']], $rows);
    }
}

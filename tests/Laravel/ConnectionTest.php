<?php

namespace SwooleTW\ClickHouse\Tests\Laravel;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Exception;
use Illuminate\Database\QueryException;
use SwooleTW\ClickHouse\Exceptions\ParallelQueryException;
use SwooleTW\ClickHouse\Laravel\Connection;
use SwooleTW\ClickHouse\Tests\TestCase;

class ConnectionTest extends TestCase
{
    public function testSelect()
    {
        $expected = [['column' => 'value']];

        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('select')->with("select * from `table` where `column` = 'value'")->once()->andReturn($statement);
        $statement->shouldReceive('rows')->withNoArgs()->once()->andReturn($expected);

        $actual = $connection->select('select * from `table` where `column` = ?', ['value']);

        $this->assertEquals($expected, $actual);
    }

    public function testInsert()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('write')->with("insert into `table` (`column`) values ('value')")->once();

        $actual = $connection->insert('insert into `table` (`column`) values (?)', ['value']);

        $this->assertTrue($actual);
    }

    public function testUpdate()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('write')->with("alter table `table` update `column` = 'value_b' where `column` = 'value_a'")->once();

        $actual = $connection->update('alter table `table` update `column` = ? where `column` = ?', ['value_b', 'value_a']);

        // TODO: correct affected rows
        $this->assertEquals(1, $actual);
    }

    public function testDelete()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('write')->with("alter table `table` delete where `column` = 'value'")->once();

        $actual = $connection->delete('alter table `table` delete where `column` = ?', ['value']);

        // TODO: correct affected rows
        $this->assertEquals(1, $actual);
    }

    public function testSelectParallelly()
    {
        $expectedA = [['column' => 'value_a']];
        $expectedB = [['column' => 'value_b']];

        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('selectAsync')->with("select * from `table_a` where `column_a` = 'value_a'")->once()->andReturn($statement);
        $client->shouldReceive('selectAsync')->with("select * from `table_b` where `column_b` = 'value_b'")->once()->andReturn($statement);
        $client->shouldReceive('executeAsync')->withNoArgs()->once();
        $statement->shouldReceive('rows')->withNoArgs()->once()->andReturn($expectedA);
        $statement->shouldReceive('rows')->withNoArgs()->once()->andReturn($expectedB);

        $actual = $connection->selectParallelly([
            'a' => ['sql' => 'select * from `table_a` where `column_a` = ?', 'bindings' => ['value_a']],
            'b' => ['sql' => 'select * from `table_b` where `column_b` = ?', 'bindings' => ['value_b']],
        ]);

        $this->assertEquals([
            'a' => $expectedA,
            'b' => $expectedB,
        ], $actual);
    }

    public function testSelectParallellyWithException()
    {
        $expectedA = [['column' => 'value_a']];

        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('selectAsync')->with("select * from `table_a` where `column_a` = 'value_a'")->once()->andReturn($statement);
        $client->shouldReceive('selectAsync')->with("select * from `table_b` where `column_b` = 'value_b'")->once()->andReturn($statement);
        $client->shouldReceive('executeAsync')->withNoArgs()->once();
        $statement->shouldReceive('rows')->withNoArgs()->once()->andReturn($expectedA);
        $statement->shouldReceive('rows')->withNoArgs()->once()->andThrow(new Exception('error'));

        try {
            $connection->selectParallelly([
                'a' => ['sql' => 'select * from `table_a` where `column_a` = ?', 'bindings' => ['value_a']],
                'b' => ['sql' => 'select * from `table_b` where `column_b` = ?', 'bindings' => ['value_b']],
            ]);
        } catch (ParallelQueryException $e) {
            $this->assertEquals(['a' => $expectedA], $e->getResults());
            $this->assertInstanceOf(QueryException::class, $e->getErrors()['b']);
        }
    }
}

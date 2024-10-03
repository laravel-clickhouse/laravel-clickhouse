<?php

namespace SwooleTW\ClickHouse\Tests\Client;

use SwooleTW\ClickHouse\Client\Client;
use SwooleTW\ClickHouse\Client\Contracts\Transport;
use SwooleTW\ClickHouse\Client\Response;
use SwooleTW\ClickHouse\Client\Statement;
use SwooleTW\ClickHouse\Support\Escaper;
use SwooleTW\ClickHouse\Tests\TestCase;

class StatementTest extends TestCase
{
    public function testExecute()
    {
        $client = $this->mock(Client::class);
        $escaper = $this->mock(Escaper::class);
        $transport = $this->mock(Transport::class);
        $response = $this->mock(Response::class);

        $client
            ->shouldReceive('getTransport')
            ->withNoArgs()
            ->once()
            ->andReturn($transport);
        $client
            ->shouldReceive('getEscaper')
            ->withNoArgs()
            ->twice()
            ->andReturn($escaper);
        $escaper
            ->shouldReceive('escape')
            ->with('value_a')
            ->once()
            ->andReturn("'value_a'");
        $escaper
            ->shouldReceive('escape')
            ->with('value_b')
            ->once()
            ->andReturn("'value_b'");
        $transport
            ->shouldReceive('execute')
            ->with("select * from `table` where `column_a` = 'value_a' and `column_b` = 'value_b'")
            ->once()
            ->andReturn($response);
        $response
            ->shouldReceive('getRecords')
            ->withNoArgs()
            ->once()
            ->andReturn($records = [1]);
        $response
            ->shouldReceive('getAffectedRows')
            ->withNoArgs()
            ->once()
            ->andReturn($affectedRows = 1);

        $statement = new Statement($client, 'select * from `table` where `column_a` = ? and `column_b` = ?');

        $statement->bindValue(1, 'value_a');
        $statement->bindValue(2, 'value_b');
        $statement->execute();

        $this->assertEquals($records, $statement->fetchAll());
        $this->assertEquals($affectedRows, $statement->rowCount());
    }
}

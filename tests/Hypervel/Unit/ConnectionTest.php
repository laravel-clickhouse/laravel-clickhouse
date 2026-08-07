<?php

namespace ClickHouse\Tests\Hypervel\Unit;

use ClickHouse\Core\Client\Client;
use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use ClickHouse\Hypervel\Query\Grammar as QueryGrammar;
use ClickHouse\Hypervel\Schema\Builder as SchemaBuilder;
use ClickHouse\Hypervel\Schema\Grammar as SchemaGrammar;
use LogicException;
use RuntimeException;

class ConnectionTest extends TestCase
{
    public function testCreatesDefaultClientAndGrammars()
    {
        $connection = new Connection('default');

        $this->assertInstanceOf(Client::class, $connection->getClient());
        $this->assertInstanceOf(QueryGrammar::class, $connection->getQueryGrammar());
        $this->assertInstanceOf(QueryBuilder::class, $connection->query());
        $this->assertInstanceOf(SchemaBuilder::class, $connection->getSchemaBuilder());
        $this->assertInstanceOf(SchemaGrammar::class, $connection->getSchemaGrammar());
    }

    public function testGetPdoThrows()
    {
        $connection = new Connection('default');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse connections do not use PDO; use getClient() instead.');

        $connection->getPdo();
    }

    public function testGetReadPdoThrows()
    {
        $connection = new Connection('default');

        $this->expectException(RuntimeException::class);

        $connection->getReadPdo();
    }

    public function testBeginTransactionThrows()
    {
        $connection = new Connection('default');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transactions are not supported when using ClickHouse.');

        $connection->beginTransaction();
    }

    public function testGetSchemaStateThrows()
    {
        $connection = new Connection('default');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema dumping is not supported when using ClickHouse.');

        $connection->getSchemaState();
    }

    public function testReconnectRebuildsTheClient()
    {
        $connection = new Connection('default');

        $original = $connection->getClient();

        $connection->reconnect();

        $this->assertNotSame($original, $connection->getClient());
    }
}

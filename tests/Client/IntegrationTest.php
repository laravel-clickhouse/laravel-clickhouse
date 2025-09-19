<?php

namespace ClickHouse\Tests\Client;

use ClickHouse\Client\Client;
use ClickHouse\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IntegrationTest extends TestCase
{
    #[DataProvider('clientProvider')]
    public function testGuzzleSelectQueries(Client $client): void
    {
        $statement = $client->prepare('SELECT 1 as test');
        $statement->execute();
        $records = $statement->fetchAll();

        $this->assertEquals([['test' => 1]], $records);
    }

    #[DataProvider('clientProvider')]
    public function testGuzzleWriteQueries(Client $client): void
    {
        $client->exec('DROP TABLE IF EXISTS test_guzzle_integration');

        $client->exec('CREATE TABLE test_guzzle_integration (id UInt32, name String) ENGINE = Memory');

        $client->exec("INSERT INTO test_guzzle_integration VALUES (1, 'test')");

        $statement = $client->prepare('SELECT * FROM test_guzzle_integration');
        $statement->execute();
        $records = $statement->fetchAll();
        $this->assertEquals([['id' => 1, 'name' => 'test']], $records);

        $client->exec('DROP TABLE IF EXISTS test_guzzle_integration');
    }

    #[DataProvider('clientProvider')]
    public function testGuzzleParallelQueries(Client $client): void
    {
        $statements = [
            'query1' => $client->prepare('SELECT 1 as first'),
            'query2' => $client->prepare('SELECT 2 as second'),
            'query3' => $client->prepare('SELECT 3 as third'),
        ];

        $client->parallel($statements);

        $this->assertCount(3, $statements);
        $this->assertEquals([['first' => 1]], $statements['query1']->fetchAll());
        $this->assertEquals([['second' => 2]], $statements['query2']->fetchAll());
        $this->assertEquals([['third' => 3]], $statements['query3']->fetchAll());
    }

    public static function clientProvider(): array
    {
        return [
            [self::createClient('curl')],
            [self::createClient('guzzle')],
        ];
    }

    private static function createClient(string $transport): Client
    {
        return new Client(
            host: getenv('CLICKHOUSE_HOST'),
            port: (int) getenv('CLICKHOUSE_PORT'),
            database: getenv('CLICKHOUSE_DATABASE'),
            username: getenv('CLICKHOUSE_USERNAME'),
            password: getenv('CLICKHOUSE_PASSWORD'),
            transport: $transport
        );
    }
}

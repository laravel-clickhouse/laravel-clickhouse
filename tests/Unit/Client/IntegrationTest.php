<?php

namespace ClickHouse\Tests\Unit\Client;

use ClickHouse\Client\Client;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Tests\Unit\TestCase;
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

    #[DataProvider('clientProvider')]
    public function testParallelFailureKeepsPartialResults(Client $client): void
    {
        $statements = [
            'good' => $client->prepare('SELECT 1 as first'),
            'bad' => $client->prepare('SELECT broken FROM missing_table'),
            'alsoGood' => $client->prepare('SELECT 3 as third'),
        ];

        try {
            $client->parallel($statements);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            // The failing query is reported without aborting the others —
            // regression guard for the rejected handler letting a
            // parse-time QueryException (plain-text error bodies on
            // ClickHouse <= 23) escape and discard the whole collection.
            // Whether the failing key also carries a salvaged partial
            // response is version-dependent (24+ JSON error bodies parse,
            // 23.x plain-text ones throw), so only the good keys and the
            // error key are asserted.
            $this->assertArrayHasKey('bad', $exception->getErrors());
            $this->assertArrayHasKey('good', $exception->getResponses());
            $this->assertArrayHasKey('alsoGood', $exception->getResponses());
            $this->assertEquals([['first' => 1]], $exception->getResponses()['good']->getRecords());
            $this->assertEquals([['third' => 3]], $exception->getResponses()['alsoGood']->getRecords());
        }
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

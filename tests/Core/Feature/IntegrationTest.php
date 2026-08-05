<?php

namespace ClickHouse\Tests\Core\Feature;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Exceptions\QueryException;
use ClickHouse\Tests\Core\Unit\TestCase;
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

    public function testGuzzleQueryErrorCarriesTheFullClickHouseMessage(): void
    {
        // Guzzle-only: the Curl transport surfaces smi2's own
        // DatabaseException, which already carries the full message.
        $statement = self::createClient('guzzle')->prepare('SELECT broken FROM missing_table');

        try {
            $statement->execute();

            $this->fail('QueryException was not thrown.');
        } catch (QueryException $exception) {
            // The full DB::Exception text must survive regardless of the
            // server's error-body style — plain text on 23.x, JSON with an
            // `exception` field on 24+ (where Guzzle's 120-character body
            // summary would otherwise truncate the message before it
            // begins).
            $this->assertStringContainsString('DB::Exception', $exception->getMessage());
            $this->assertStringContainsString('missing_table', $exception->getMessage());
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

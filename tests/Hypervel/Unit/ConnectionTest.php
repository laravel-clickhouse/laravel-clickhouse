<?php

namespace ClickHouse\Tests\Hypervel\Unit;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Client\Response;
use ClickHouse\Core\Client\Statement;
use ClickHouse\Core\Client\Transports\Guzzle as GuzzleTransport;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use ClickHouse\Hypervel\Query\Grammar as QueryGrammar;
use ClickHouse\Hypervel\Schema\Builder as SchemaBuilder;
use ClickHouse\Hypervel\Schema\Grammar as SchemaGrammar;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Swoole\Coroutine\CanceledException;

class ConnectionTest extends TestCase
{
    public function testUsesDefaultDatabaseForConnectionAndClient()
    {
        $connection = new ConnectionWithCapturedDatabase;

        $this->assertSame('default', $connection->getDatabaseName());
        $this->assertSame('default', $connection->defaultClientDatabase);
    }

    public function testPropagatesConfiguredConnectTimeoutToTheClient()
    {
        $connection = new Connection(config: ['connect_timeout' => '1.25']);
        $transport = $connection->getClient()->getTransport();
        $client = (new ReflectionProperty(GuzzleTransport::class, 'client'))->getValue($transport);

        $this->assertInstanceOf(GuzzleClient::class, $client);
        $this->assertSame(1.25, $client->getConfig('connect_timeout'));
    }

    public function testSelect()
    {
        $expected = [['column' => 'value']];

        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);
        $connection->enableQueryLog();

        $query = 'select * from `table` where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query, null)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0])->once();
        $statement->shouldReceive('execute')->withNoArgs()->once();
        $statement->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expected);

        $actual = $connection->select($query, $bindings);

        $this->assertEquals($expected, $actual);
        $this->assertSame($query, $connection->getQueryLog()[0]['query']);
        $this->assertSame($bindings, $connection->getQueryLog()[0]['bindings']);
    }

    public function testNamedBindingsAreRejected()
    {
        $statement = $this->mock(Statement::class);
        $statement->shouldNotReceive('bindValue');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClickHouse only supports positional bindings.');

        (new Connection(client: $this->mock(Client::class)))->bindValues($statement, ['name' => 'value']);
    }

    public function testCursorYieldsSelectedRecords()
    {
        $expected = [['column' => 'value']];
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($query = 'select * from `table`', null)->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once();
        $statement->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expected);

        $this->assertSame($expected, iterator_to_array($connection->cursor($query)));
    }

    public function testPretendModeLogsEveryQueryWithoutUsingTheClient()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);
        $beforeExecutingCalls = [];
        $results = [];

        $client->shouldNotReceive('prepare');
        $client->shouldNotReceive('getTransport');
        $client->shouldNotReceive('parallel');

        $connection->beforeExecuting(function ($query, $bindings) use (&$beforeExecutingCalls) {
            $beforeExecutingCalls[] = [$query, $bindings];
        });

        $queryLog = $connection->pretend(function (Connection $connection) use (&$results) {
            $results['select'] = $connection->select('select ?', [1]);
            $results['statement'] = $connection->statement('insert into `events` values (?)', [1]);
            $results['affecting'] = $connection->affectingStatement('alter table `events` delete where id = ?', [1]);
            $results['formatted'] = $connection->insertRawPayload('insert into `events` format JSONEachRow', '{"id":1}');
            $results['parallel'] = $connection->selectParallelly([
                'first' => ['sql' => 'select ?', 'bindings' => [1]],
                'second' => ['sql' => 'select ?', 'bindings' => [2]],
            ]);
            $results['cursor'] = iterator_to_array($connection->cursor('select ?', [3]));
        });

        $this->assertSame([], $results['select']);
        $this->assertTrue($results['statement']);
        $this->assertSame(0, $results['affecting']);
        $this->assertTrue($results['formatted']);
        $this->assertSame(['first' => [], 'second' => []], $results['parallel']);
        $this->assertSame([], $results['cursor']);
        $this->assertCount(7, $beforeExecutingCalls);
        $this->assertCount(7, $queryLog);
        $this->assertSame([
            'select 1',
            'insert into `events` values (1)',
            'alter table `events` delete where id = 1',
            'insert into `events` format JSONEachRow',
            'select 1',
            'select 2',
            'select 3',
        ], array_column($queryLog, 'query'));
        $this->assertFalse($connection->hasModifiedRecords());
    }

    public function testInsert()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'insert into `table` (`column`) values (?)';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query, null)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0])->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();

        $actual = $connection->insert($query, $bindings);

        $this->assertTrue($actual);
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testUnpreparedUsesStatementExecution()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($query = 'optimize table `events`', null)->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();

        $this->assertTrue($connection->unprepared($query));
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testStatementMarksRecordsAsModifiedBeforeExecutionFails()
    {
        $failure = new RuntimeException('statement failed');
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($query = 'insert into `events` values (1)', null)->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once()->andThrow($failure);

        $caught = null;

        try {
            $connection->statement($query);
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught?->getPrevious());
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testConfiglessQueryFailureRetainsItsOriginalExceptionAndNullableConnectionName()
    {
        $failure = new RuntimeException('query failed');
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($query = 'select 1', null)->once()->andThrow($failure);

        try {
            $connection->select($query);
            $this->fail('Expected query execution to fail.');
        } catch (QueryException $exception) {
            $this->assertNull($exception->connectionName);
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testInsertUsingFormat()
    {
        $client = $this->mock(Client::class);
        $transport = $this->mock(Transport::class);
        $connection = new Connection(client: $client);

        $query = 'insert into `table` (`id`) format JSONEachRow';
        $data = '{"id":1}'."\n".'{"id":2}';

        $client->shouldReceive('getTransport')->with(null)->once()->andReturn($transport);
        $transport->shouldReceive('execute')
            ->with($query."\n".$data)
            ->once()
            ->andReturn(new Response($query, affectedRows: 2));

        $this->assertTrue($connection->insertRawPayload($query, $data));
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testFormattedInsertMarksRecordsAsModifiedBeforeExecutionFails()
    {
        $failure = new RuntimeException('formatted insert failed');
        $client = $this->mock(Client::class);
        $transport = $this->mock(Transport::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('getTransport')->with(null)->once()->andReturn($transport);
        $transport->shouldReceive('execute')
            ->with(($query = 'insert into `events` format JSONEachRow')."\n".($payload = '{"id":1}'))
            ->once()
            ->andThrow($failure);

        $caught = null;

        try {
            $connection->insertRawPayload($query, $payload);
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught?->getPrevious());
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testUpdate()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'alter table `table` update `column` = ? where `column` = ?';
        $bindings = ['value_b', 'value_a'];

        $client->shouldReceive('prepare')->with($query, null)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0])->once();
        $statement->shouldReceive('bindValue')->with(2, $bindings[1])->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturn($rowCount = 1);

        $actual = $connection->update($query, $bindings);

        $this->assertEquals($rowCount, $actual);
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testDelete()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'alter table `table` delete where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query, null)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0])->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturn($rowCount = 1);

        $actual = $connection->delete($query, $bindings);

        $this->assertEquals($rowCount, $actual);
        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testDeleteWithoutAffectedRowsSummary()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'delete from `table` where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query, null)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0])->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturnNull();

        $this->assertSame(0, $connection->delete($query, $bindings));
        $this->assertFalse($connection->hasModifiedRecords());
    }

    public function testZeroAffectedRowsDoNotMarkRecordsAsModified()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($query = 'alter table `table` delete where 0', null)->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturn(0);

        $this->assertSame(0, $connection->delete($query));
        $this->assertFalse($connection->hasModifiedRecords());
    }

    public function testSelectParallelly()
    {
        $expectedA = [['column' => 'value_a']];
        $expectedB = [['column' => 'value_b']];

        $client = $this->mock(Client::class);
        $statementA = $this->mock(Statement::class);
        $statementB = $this->mock(Statement::class);
        $connection = new Connection(client: $client);
        $connection->enableQueryLog();

        $client->shouldReceive('prepare')->with($sqlA = 'select * from `table_a` where `column_a` = ?', null)->once()->andReturn($statementA);
        $client->shouldReceive('prepare')->with($sqlB = 'select * from `table_b` where `column_b` = ?', null)->once()->andReturn($statementB);
        $client->shouldReceive('parallel')->with(['a' => $statementA, 'b' => $statementB])->once();
        $statementA->shouldReceive('bindValue')->with(1, $bindingA = 'value_a')->once();
        $statementA->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expectedA);
        $statementB->shouldReceive('bindValue')->with(1, $bindingB = 'value_b')->once();
        $statementB->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expectedB);

        $actual = $connection->selectParallelly([
            'a' => ['sql' => $sqlA, 'bindings' => [$bindingA]],
            'b' => ['sql' => $sqlB, 'bindings' => [$bindingB]],
        ]);

        $this->assertEquals([
            'a' => $expectedA,
            'b' => $expectedB,
        ], $actual);
        $this->assertSame([$sqlA, $sqlB], array_column($connection->getQueryLog(), 'query'));
    }

    public function testSelectParallellyWithException()
    {
        $expectedA = [['column' => 'value_a']];

        $client = $this->mock(Client::class);
        $statementA = $this->mock(Statement::class);
        $statementB = $this->mock(Statement::class);
        $connection = new Connection(config: ['name' => '0'], client: $client);
        $exception = new ParallelQueryException(['a' => $expectedA], ['b' => new Exception('error')]);

        $client->shouldReceive('prepare')->with($sqlA = 'select * from `table_a` where `column_a` = ?', null)->once()->andReturn($statementA);
        $client->shouldReceive('prepare')->with($sqlB = 'select * from `table_b` where `column_b` = ?', null)->once()->andReturn($statementB);
        $client->shouldReceive('parallel')->with(['a' => $statementA, 'b' => $statementB])->once()->andThrow($exception);
        $statementA->shouldReceive('bindValue')->with(1, $bindingA = 'value_a')->once();
        $statementB->shouldReceive('bindValue')->with(1, $bindingB = 'value_b')->once();

        try {
            $connection->selectParallelly([
                'a' => ['sql' => $sqlA, 'bindings' => [$bindingA]],
                'b' => ['sql' => $sqlB, 'bindings' => [$bindingB]],
            ]);
            $this->fail('Expected parallel query execution to fail.');
        } catch (ParallelQueryException $e) {
            $this->assertEquals(['a' => $expectedA], $e->getResponses());
            $this->assertInstanceOf(QueryException::class, $e->getErrors()['b']);
            $this->assertSame('0', $e->getErrors()['b']->connectionName);
        }
    }

    public function testReportsDefaultAndConfiguredDriverNames()
    {
        $client = $this->mock(Client::class);

        $this->assertSame('clickhouse', (new Connection(client: $client))->getDriverName());
        $this->assertSame('analytics', (new Connection(config: ['driver' => 'analytics'], client: $client))->getDriverName());
    }

    public function testReportsDriverTitleAndServerVersion()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with('SELECT version()', null)->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once();
        $statement->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn([['version()' => '25.8.1.1']]);

        $this->assertSame('ClickHouse', $connection->getDriverTitle());
        $this->assertSame('25.8.1.1', $connection->getServerVersion());
    }

    public function testLastInsertIdThrowsLogicException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support retrieving last insert IDs.');

        (new Connection(client: $this->mock(Client::class)))->getLastInsertId();
    }

    public function testConnectionIsNeverInATransaction()
    {
        $this->assertFalse((new Connection(client: $this->mock(Client::class)))->inTransaction());
    }

    public function testEscapesClickHouseValues()
    {
        $connection = new Connection(client: $this->mock(Client::class));
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return "Taylor's value";
            }
        };

        $this->assertSame("[1, 'two']", $connection->escape([1, 'two']));
        $this->assertSame("'2026-08-24 12:34:56'", $connection->escape(new DateTimeImmutable('2026-08-24 12:34:56')));
        $this->assertSame("'Taylor\\'s value'", $connection->escape($stringable));
    }

    public function testEscapeRejectsNullBytes()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strings with null bytes cannot be escaped. Use the binary escape option.');

        (new Connection(client: $this->mock(Client::class)))->escape("null\0byte");
    }

    public function testEscapeRejectsInvalidUtf8()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strings with invalid UTF-8 byte sequences cannot be escaped.');

        (new Connection(client: $this->mock(Client::class)))->escape("\xB1\x31");
    }

    public function testEscapeRejectsBinaryValues()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database connection does not support escaping binary values.');

        (new Connection(client: $this->mock(Client::class)))->escape('binary', true);
    }

    public function testBeginTransactionThrowsLogicException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transactions are not supported when using ClickHouse.');

        (new Connection(client: $this->mock(Client::class)))->beginTransaction();
    }

    public function testCommitThrowsLogicException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transactions are not supported when using ClickHouse.');

        (new Connection(client: $this->mock(Client::class)))->commit();
    }

    public function testRollBackThrowsLogicException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transactions are not supported when using ClickHouse.');

        (new Connection(client: $this->mock(Client::class)))->rollBack();
    }

    public function testTransactionClosureThrowsLogicException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transactions are not supported when using ClickHouse.');

        (new Connection(client: $this->mock(Client::class)))->transaction(fn () => null);
    }

    public function testCreatesDefaultClientAndGrammars()
    {
        $connection = new Connection('default');

        $this->assertInstanceOf(Client::class, $connection->getClient());
        $this->assertInstanceOf(QueryGrammar::class, $connection->getQueryGrammar());
        $this->assertInstanceOf(QueryBuilder::class, $connection->query());
        $this->assertInstanceOf(SchemaBuilder::class, $connection->getSchemaBuilder());
        $this->assertInstanceOf(SchemaGrammar::class, $connection->getSchemaGrammar());
    }

    public function testGetSchemaStateThrows()
    {
        $connection = new Connection('default');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema dumping is not supported when using ClickHouse.');

        $connection->getSchemaState();
    }

    public function testDisconnectForgetsClientAndPingReturnsFalse()
    {
        $connection = new Connection(client: $this->mock(Client::class));

        $connection->disconnect();

        $this->assertFalse($connection->ping());
    }

    public function testGetClientReconnectsAfterDisconnect()
    {
        $freshClient = $this->mock(Client::class);
        $connection = new Connection(client: $this->mock(Client::class));
        $connection->setReconnector(function (Connection $connection) use ($freshClient) {
            $connection->refreshFrom(new Connection(client: $freshClient));
        });

        $connection->disconnect();

        $this->assertSame($freshClient, $connection->getClient());
    }

    public function testRefreshTransfersDriverMetadataAndRetainsWrapperState()
    {
        $oldClient = $this->mock(Client::class);
        $freshClient = $this->mock(Client::class);
        $connection = new InspectableConnection(
            database: 'old_configured',
            tablePrefix: 'old_',
            config: ['name' => 'clickhouse', 'driver' => 'clickhouse', 'read_write_type' => 'write', 'marker' => 'old'],
            client: $oldClient,
        );
        $connection->setDatabaseName('old_current');
        $connection->setTablePrefix('old_runtime_');
        $connection->setReadConnectionConfigForTest(['host' => 'old-read']);
        $connection->setLatestReadWriteTypeForTest('read');
        $connection->enableQueryLog();
        $connection->recordsHaveBeenModified();

        $fresh = new InspectableConnection(
            database: 'fresh_configured',
            tablePrefix: 'fresh_',
            config: ['name' => 'clickhouse', 'driver' => 'clickhouse', 'read_write_type' => 'read', 'marker' => 'fresh'],
            client: $freshClient,
        );
        $fresh->setDatabaseName('fresh_current');
        $fresh->setTablePrefix('fresh_runtime_');
        $fresh->setReadConnectionConfigForTest(['host' => 'fresh-read']);
        $fresh->setLatestReadWriteTypeForTest('write');

        $connection->refreshFrom($fresh);

        $this->assertSame($freshClient, $connection->getClient());
        $this->assertSame('fresh_current', $connection->getDatabaseName());
        $this->assertSame('fresh_runtime_', $connection->getTablePrefix());
        $this->assertSame('fresh', $connection->getConfig('marker'));
        $this->assertSame(['host' => 'fresh-read'], $connection->getReadConnectionConfigForTest());
        $this->assertSame('read', $connection->getConfiguredReadWriteTypeForTest());
        $this->assertNull($connection->getLatestReadWriteTypeForTest());
        $this->assertTrue($connection->logging());
        $this->assertTrue($connection->hasModifiedRecords());

        $connection->resetForPool();

        $this->assertSame('fresh_configured', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
    }

    public function testRefreshRejectsMissingFreshClientBeforeDisconnectingCurrentClient()
    {
        $currentClient = $this->mock(Client::class);
        $connection = new Connection(config: ['name' => 'clickhouse'], client: $currentClient);
        $fresh = new Connection(config: ['name' => 'clickhouse'], client: $this->mock(Client::class));
        $fresh->disconnect();

        try {
            $connection->refreshFrom($fresh);
            $this->fail('Expected the missing fresh client to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('The fresh ClickHouse connection has no client.', $exception->getMessage());
        }

        $this->assertSame($currentClient, $connection->getClient());
    }

    public function testRefreshAdoptsFreshGenerationWhenTransactionCleanupFails()
    {
        $failure = new RuntimeException('transaction cleanup failed');
        $freshClient = $this->mock(Client::class);
        $transactions = $this->mock(DatabaseTransactionsManager::class);
        $transactions->shouldReceive('rollback')->with('clickhouse', 0)->once()->andThrow($failure);
        $connection = new InspectableConnection(
            database: 'old',
            config: ['name' => 'clickhouse'],
            client: $this->mock(Client::class),
        );
        $connection->setTransactionManager($transactions);
        $fresh = new InspectableConnection(
            database: 'fresh',
            tablePrefix: 'fresh_',
            config: ['name' => 'clickhouse', 'marker' => 'fresh'],
            client: $freshClient,
        );

        try {
            $connection->refreshFrom($fresh);
            $this->fail('Expected transaction cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($freshClient, $connection->getClient());
        $this->assertSame('fresh', $connection->getDatabaseName());
        $this->assertSame('fresh_', $connection->getTablePrefix());
        $this->assertSame('fresh', $connection->getConfig('marker'));
    }

    public function testPingReturnsFalseForOrdinaryFailures()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $client->shouldReceive('prepare')->with('SELECT 1')->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once()->andThrow(new RuntimeException('unreachable'));

        $this->assertFalse((new Connection(client: $client))->ping());
    }

    public function testPingRethrowsCancellationUnchanged()
    {
        $cancellation = new CanceledException;
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $client->shouldReceive('prepare')->with('SELECT 1')->once()->andReturn($statement);
        $statement->shouldReceive('execute')->withNoArgs()->once()->andThrow($cancellation);

        try {
            (new Connection(client: $client))->ping();
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }
}

class ConnectionWithCapturedDatabase extends Connection
{
    public string $defaultClientDatabase;

    protected function getDefaultClient(string $database, array $config): Client
    {
        $this->defaultClientDatabase = $database;

        return parent::getDefaultClient($database, $config);
    }
}

class InspectableConnection extends Connection
{
    public function setReadConnectionConfigForTest(array $config): void
    {
        $this->readConnectionConfig = $config;
    }

    public function getReadConnectionConfigForTest(): array
    {
        return $this->readConnectionConfig;
    }

    public function setLatestReadWriteTypeForTest(?string $type): void
    {
        $this->latestReadWriteTypeRetrieved = $type;
    }

    public function getConfiguredReadWriteTypeForTest(): ?string
    {
        return $this->readWriteType;
    }

    public function getLatestReadWriteTypeForTest(): ?string
    {
        return $this->latestReadWriteTypeRetrieved;
    }
}

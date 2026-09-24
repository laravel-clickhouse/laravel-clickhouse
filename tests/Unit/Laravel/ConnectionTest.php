<?php

namespace ClickHouse\Tests\Unit\Laravel;

use Carbon\Carbon;
use ClickHouse\Client\Client;
use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Response;
use ClickHouse\Client\Statement;
use ClickHouse\Enums\DateTimePrecision;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Laravel\Connection;
use ClickHouse\Support\Escaper;
use ClickHouse\Tests\Unit\TestCase;
use Exception;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use PDO;
use ReflectionProperty;

class ConnectionTest extends TestCase
{
    public function testSelect()
    {
        $expected = [['column' => 'value']];

        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'select * from `table` where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0], PDO::PARAM_STR)->once();
        $statement->shouldReceive('execute')->withNoArgs()->once();
        $statement->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expected);

        $actual = $connection->select($query, $bindings);

        $this->assertEquals($expected, $actual);
    }

    public function testSessionRunsCallbackWithSessionAndRestoresClientState()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('getSession')->once()->andReturnNull();
        $client->shouldReceive('startSession')
            ->withArgs(fn (string $sessionId, int $timeout) => ctype_xdigit($sessionId) && strlen($sessionId) === 32 && $timeout === 120)
            ->once();
        $client->shouldReceive('endSession')->once();

        $this->assertSame('result', $connection->session(
            fn ($session) => $session === $connection ? 'result' : null,
            120,
        ));
    }

    public function testNestedSessionRestoresPreviousSession()
    {
        $client = $this->mock(Client::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('getSession')
            ->once()
            ->andReturn(['id' => 'outer-session', 'timeout' => 30]);
        $client->shouldReceive('startSession')
            ->withArgs(fn (string $sessionId, int $timeout) => ctype_xdigit($sessionId) && strlen($sessionId) === 32 && $timeout === 120)
            ->once();
        $client->shouldReceive('startSession')->with('outer-session', 30)->once();
        $client->shouldNotReceive('endSession');

        $connection->session(fn () => null, 120);
    }

    public function testSessionRejectsNonPositiveTimeout()
    {
        $this->expectException(LogicException::class);

        (new Connection(client: $this->mock(Client::class)))->session(fn () => null, 0);
    }

    public function testPrepareBindingsKeepsDateTimeInterfaceIntact()
    {
        $connection = new Connection(client: $this->mock(Client::class));

        $date = Carbon::parse('2026-08-13 10:00:00.123456');

        $prepared = $connection->prepareBindings([$date, true, 'value']);

        $this->assertSame($date, $prepared[0]);
        $this->assertSame(1, $prepared[1]);
        $this->assertSame('value', $prepared[2]);
    }

    public function testDateTimePrecisionDefaultsToSecond()
    {
        $connection = new Connection;

        $this->assertSame(DateTimePrecision::Second, $connection->getDateTimePrecision());
        $this->assertSame("'2026-08-13 10:00:00'", $connection->escape(Carbon::parse('2026-08-13 10:00:00.123456')));
    }

    public function testDateTimePrecisionMicrosecondFromConfig()
    {
        $connection = new Connection(config: ['datetime_precision' => 'microsecond']);

        $this->assertSame(DateTimePrecision::Microsecond, $connection->getDateTimePrecision());
        $this->assertSame(DateTimePrecision::Microsecond, $connection->getClient()->getEscaper()->getDateTimePrecision());
        $this->assertSame(
            "toDateTime64('2026-08-13 10:00:00.123456', 6)",
            $connection->escape(Carbon::parse('2026-08-13 10:00:00.123456'))
        );
    }

    public function testDateTimePrecisionAcceptsEnumInConfig()
    {
        $connection = new Connection(config: ['datetime_precision' => DateTimePrecision::Microsecond]);

        $this->assertSame(DateTimePrecision::Microsecond, $connection->getDateTimePrecision());
        $this->assertSame(
            "toDateTime64('2026-08-13 10:00:00.123456', 6)",
            $connection->escape(Carbon::parse('2026-08-13 10:00:00.123456'))
        );
    }

    public function testInjectedEscaperIsUsedByDefaultClient()
    {
        $escaper = new Escaper(DateTimePrecision::Microsecond);

        $connection = new Connection(config: ['datetime_precision' => 'second'], escaper: $escaper);

        $this->assertSame($escaper, $connection->getClient()->getEscaper());
        $this->assertSame(DateTimePrecision::Microsecond, $connection->getDateTimePrecision());
    }

    public function testDateTimePrecisionFollowsInjectedClientEscaper()
    {
        $client = $this->mock(Client::class);

        $client
            ->shouldReceive('getEscaper')
            ->andReturn(new Escaper(DateTimePrecision::Microsecond));

        $connection = new Connection(config: ['datetime_precision' => 'second'], client: $client);

        $this->assertSame(DateTimePrecision::Microsecond, $connection->getDateTimePrecision());
        $this->assertSame(
            "toDateTime64('2026-08-13 10:00:00.123456', 6)",
            $connection->escape(Carbon::parse('2026-08-13 10:00:00.123456'))
        );
    }

    public function testInjectingClientAndDifferentEscaperThrows()
    {
        $client = $this->mock(Client::class);

        $client
            ->shouldReceive('getEscaper')
            ->andReturn(new Escaper);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An injected client brings its own escaper; pass the escaper to the client instead.');

        new Connection(client: $client, escaper: new Escaper);
    }

    public function testInvalidDateTimePrecisionThrows()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid datetime_precision "millisecond". Valid values: second, microsecond.');

        new Connection(config: ['datetime_precision' => 'millisecond']);
    }

    public function testInsert()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'insert into `table` (`column`) values (?)';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0], PDO::PARAM_STR)->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();

        $actual = $connection->insert($query, $bindings);

        $this->assertTrue($actual);
    }

    public function testInsertUsingFormat()
    {
        $client = $this->mock(Client::class);
        $transport = $this->mock(Transport::class);
        $connection = new Connection(client: $client);

        $query = 'insert into `table` (`id`) format JSONEachRow';
        $data = '{"id":1}'."\n".'{"id":2}';

        $client->shouldReceive('getTransport')->withNoArgs()->once()->andReturn($transport);
        $transport->shouldReceive('execute')
            ->with($query."\n".$data)
            ->once()
            ->andReturn(new Response($query, affectedRows: 2));

        $this->assertTrue($connection->insertUsingFormat($query, $data));
    }

    public function testUpdate()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'alter table `table` update `column` = ? where `column` = ?';
        $bindings = ['value_b', 'value_a'];

        $client->shouldReceive('prepare')->with($query)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0], PDO::PARAM_STR)->once();
        $statement->shouldReceive('bindValue')->with(2, $bindings[1], PDO::PARAM_STR)->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturn($rowCount = 1);

        $actual = $connection->update($query, $bindings);

        $this->assertEquals($rowCount, $actual);
    }

    public function testDelete()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'alter table `table` delete where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0], PDO::PARAM_STR)->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturn($rowCount = 1);

        $actual = $connection->delete($query, $bindings);

        $this->assertEquals($rowCount, $actual);
    }

    public function testDeleteWithoutAffectedRowsSummary()
    {
        $client = $this->mock(Client::class);
        $statement = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $query = 'delete from `table` where `column` = ?';
        $bindings = ['value'];

        $client->shouldReceive('prepare')->with($query)->once()->andReturn($statement);
        $statement->shouldReceive('bindValue')->with(1, $bindings[0], PDO::PARAM_STR)->once();
        $statement->shouldReceive('execute')->withNoArgs()->once()->andReturnTrue();
        $statement->shouldReceive('rowCount')->withNoArgs()->once()->andReturnNull();

        $this->assertSame(0, $connection->delete($query, $bindings));
    }

    public function testSelectParallelly()
    {
        $expectedA = [['column' => 'value_a']];
        $expectedB = [['column' => 'value_b']];

        $client = $this->mock(Client::class);
        $statementA = $this->mock(Statement::class);
        $statementB = $this->mock(Statement::class);
        $connection = new Connection(client: $client);

        $client->shouldReceive('prepare')->with($sqlA = 'select * from `table_a` where `column_a` = ?')->once()->andReturn($statementA);
        $client->shouldReceive('prepare')->with($sqlB = 'select * from `table_b` where `column_b` = ?')->once()->andReturn($statementB);
        $client->shouldReceive('parallel')->with(['a' => $statementA, 'b' => $statementB])->once();
        $statementA->shouldReceive('bindValue')->with(1, $bindingA = 'value_a', PDO::PARAM_STR)->once();
        $statementA->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expectedA);
        $statementB->shouldReceive('bindValue')->with(1, $bindingB = 'value_b', PDO::PARAM_STR)->once();
        $statementB->shouldReceive('fetchAll')->withNoArgs()->once()->andReturn($expectedB);

        $actual = $connection->selectParallelly([
            'a' => ['sql' => $sqlA, 'bindings' => [$bindingA]],
            'b' => ['sql' => $sqlB, 'bindings' => [$bindingB]],
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
        $statementA = $this->mock(Statement::class);
        $statementB = $this->mock(Statement::class);
        $connection = new Connection(client: $client);
        $exception = new ParallelQueryException(['a' => $expectedA], ['b' => new Exception('error')]);

        $client->shouldReceive('prepare')->with($sqlA = 'select * from `table_a` where `column_a` = ?')->once()->andReturn($statementA);
        $client->shouldReceive('prepare')->with($sqlB = 'select * from `table_b` where `column_b` = ?')->once()->andReturn($statementB);
        $client->shouldReceive('parallel')->with(['a' => $statementA, 'b' => $statementB])->once()->andThrow($exception);
        $statementA->shouldReceive('bindValue')->with(1, $bindingA = 'value_a', PDO::PARAM_STR)->once();
        $statementB->shouldReceive('bindValue')->with(1, $bindingB = 'value_b', PDO::PARAM_STR)->once();

        try {
            $connection->selectParallelly([
                'a' => ['sql' => $sqlA, 'bindings' => [$bindingA]],
                'b' => ['sql' => $sqlB, 'bindings' => [$bindingB]],
            ]);
        } catch (ParallelQueryException $e) {
            $this->assertEquals(['a' => $expectedA], $e->getResponses());
            $this->assertInstanceOf(QueryException::class, $e->getErrors()['b']);
        }
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

    public function testTimeoutConfigIsPassedToClient()
    {
        $connection = new Connection(config: ['timeout' => '30', 'connect_timeout' => 2.5]);
        $client = (new ReflectionProperty($connection, 'client'))->getValue($connection);

        $this->assertSame(30.0, (new ReflectionProperty($client, 'timeout'))->getValue($client));
        $this->assertSame(2.5, (new ReflectionProperty($client, 'connectTimeout'))->getValue($client));
    }

    public function testMissingOrBlankTimeoutConfigIsNull()
    {
        $connection = new Connection(config: ['timeout' => '']);
        $client = (new ReflectionProperty($connection, 'client'))->getValue($connection);

        $this->assertNull((new ReflectionProperty($client, 'timeout'))->getValue($client));
        $this->assertNull((new ReflectionProperty($client, 'connectTimeout'))->getValue($client));
    }

    public function testNonNumericTimeoutConfigThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [timeout] config value must be numeric.');

        new Connection(config: ['timeout' => 'forever']);
    }
}

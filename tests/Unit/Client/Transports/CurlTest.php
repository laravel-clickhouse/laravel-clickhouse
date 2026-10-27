<?php

namespace ClickHouse\Tests\Unit\Client\Transports;

use ClickHouse\Client\Transports\Curl;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Exceptions\QueryException;
use ClickHouse\Tests\Unit\TestCase;
use ClickHouseDB\Client as ClickHouseDBClient;
use ClickHouseDB\Statement as ClickHouseDBStatement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use Exception;
use ReflectionProperty;

class CurlTest extends TestCase
{
    protected const CLICKHOUSE_ERROR = "Code: 62. DB::Exception: Syntax error: failed at position 1 ('SELEC'): SELEC 1. (SYNTAX_ERROR) (version 23.11.3.23 (official build))";

    protected const CLICKHOUSE_STREAM_ERROR = "Code: 395. DB::Exception: Value passed to 'throwIf' function is non-zero: while executing 'FUNCTION throwIf(equals(number, 500000) :: 2) -> throwIf(equals(number, 500000)) UInt8 : 1'. (FUNCTION_THROW_IF_VALUE_IS_NON_ZERO) (version 23.11.3.23 (official build))";

    public function testJsonExceptionFieldInSuccessfulResponseThrows(): void
    {
        // ClickHouse <= 23 reports even a syntax error as HTTP 200 with a
        // valid JSON body, which phpclickhouse does not flag as an error.
        $transport = $this->getTransportReturning([
            'SELEC 1' => $this->jsonBody(data: [], exception: self::CLICKHOUSE_ERROR),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ClickHouse query error: '.self::CLICKHOUSE_ERROR);

        $transport->execute('SELEC 1');
    }

    public function testJsonExceptionFieldAfterPartialDataThrowsInsteadOfReturningTruncatedRecords(): void
    {
        $sql = 'SELECT number, throwIf(number = 500000) FROM numbers(1000000)';
        $data = array_map(fn ($number) => ['number' => $number], range(0, 999));

        $transport = $this->getTransportReturning([
            $sql => $this->jsonBody(data: $data, exception: self::CLICKHOUSE_STREAM_ERROR),
        ]);

        try {
            $transport->execute($sql);

            $this->fail('No exception was thrown for a query that failed midway.');
        } catch (Exception $exception) {
            // phpclickhouse < 1.26.610 flags this body itself through its
            // tail regex, so only the surfaced message is asserted.
            $this->assertStringContainsString("Value passed to 'throwIf' function is non-zero", $exception->getMessage());
        }
    }

    public function testExceptionTextInsideDataIsNotMistakenForAnError(): void
    {
        $records = [['message' => self::CLICKHOUSE_ERROR]];

        $transport = $this->getTransportReturning([
            'SELECT message FROM logs' => $this->jsonBody(data: $records),
        ]);

        $this->assertSame($records, $transport->execute('SELECT message FROM logs')->getRecords());
    }

    public function testParallelJsonExceptionFieldIsReportedAsThatQuerysError(): void
    {
        $transport = $this->getTransportReturning([
            'SELECT 1 as first' => $this->jsonBody(data: [['first' => 1]]),
            'SELEC 1' => $this->jsonBody(data: [], exception: self::CLICKHOUSE_ERROR),
        ]);

        try {
            $transport->executeParallelly([
                'good' => 'SELECT 1 as first',
                'bad' => 'SELEC 1',
            ]);

            $this->fail('ParallelQueryException was not thrown.');
        } catch (ParallelQueryException $exception) {
            $this->assertArrayHasKey('good', $exception->getResponses());
            $this->assertSame([['first' => 1]], $exception->getResponses()['good']->getRecords());

            $this->assertArrayHasKey('bad', $exception->getErrors());
            $this->assertInstanceOf(QueryException::class, $exception->getErrors()['bad']);
            $this->assertSame(
                'ClickHouse query error: '.self::CLICKHOUSE_ERROR,
                $exception->getErrors()['bad']->getMessage(),
            );
        }
    }

    public function testTimeoutsAreAppliedToClient(): void
    {
        $client = $this->getClient(timeout: 30, connectTimeout: 2.5);

        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(2.5, $client->getConnectTimeOut());
    }

    public function testSubSecondTimeoutIsRoundedUpInsteadOfBecomingUnlimited(): void
    {
        $this->assertSame(1, $this->getClient(timeout: 0.5)->getTimeout());
    }

    public function testZeroTimeoutDisablesLimit(): void
    {
        $this->assertSame(0, $this->getClient(timeout: 0)->getTimeout());
    }

    public function testLibraryDefaultsAreKeptWhenTimeoutsAreNotConfigured(): void
    {
        $client = $this->getClient();

        $this->assertSame(20, $client->getTimeout());
        $this->assertSame(5.0, $client->getConnectTimeOut());
    }

    protected function getClient(?float $timeout = null, ?float $connectTimeout = null): ClickHouseDBClient
    {
        $transport = new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );

        /** @var ClickHouseDBClient */
        return (new ReflectionProperty($transport, 'client'))->getValue($transport);
    }

    /**
     * @param  array<string, string>  $bodies  HTTP 200 JSON response body keyed by SQL
     */
    protected function getTransportReturning(array $bodies): Curl
    {
        $statements = array_map(fn ($body) => $this->makeStatement($body), $bodies);

        $client = $this->mock(ClickHouseDBClient::class, function ($mock) use ($statements) {
            $mock->shouldReceive('write')->andReturnUsing(fn (string $sql) => $statements[$sql]);
            $mock->shouldReceive('selectAsync')->andReturnUsing(fn (string $sql) => $statements[$sql]);
            $mock->shouldReceive('executeAsync');
        });

        return new Curl(
            host: 'localhost',
            port: 8123,
            database: 'default',
            username: 'default',
            password: '',
            client: $client,
        );
    }

    protected function makeStatement(string $body): ClickHouseDBStatement
    {
        $response = new CurlerResponse;
        $response->_body = $body;
        $response->_info = ['http_code' => 200, 'content_type' => 'application/json; charset=UTF-8'];

        $request = new CurlerRequest;
        $request->setResponse($response);

        return new ClickHouseDBStatement($request);
    }

    /**
     * Build a ClickHouse JSON format body, optionally ending with the
     * `exception` field written by http_write_exception_in_output_format.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    protected function jsonBody(array $data, ?string $exception = null): string
    {
        $body = ['meta' => [], 'data' => $data, 'rows' => count($data)];

        if ($exception !== null) {
            $body['exception'] = $exception;
        }

        return (string) json_encode($body, JSON_PRETTY_PRINT);
    }
}

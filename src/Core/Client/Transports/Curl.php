<?php

namespace ClickHouse\Core\Client\Transports;

use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Client\Response;
use ClickHouse\Core\Client\Session;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Exceptions\QueryException;
use ClickHouseDB\Client;
use ClickHouseDB\Statement as ClickHouseDBStatement;
use Exception;

class Curl implements Transport
{
    protected Client $client;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected bool $https = false,
        ?Client $client = null,
        protected ?Session $session = null,
        protected ?float $timeout = null,
        protected ?float $connectTimeout = null,
    ) {
        $this->client = $client ?? $this->getDefaultClient();
    }

    public function execute(string $sql): Response
    {
        /** @var ClickHouseDBStatement $statement */
        $statement = $this->client->write($sql, querySettings: $this->querySettings());

        return $this->parseResponse($sql, $statement);
    }

    public function executeParallelly(array $sqls): array
    {
        $statements = array_map(function ($sql) {
            return $this->client->selectAsync(
                $sql,
                querySettings: $this->querySettings(),
            );
        }, $sqls);

        $this->client->executeAsync();

        $results = ['responses' => [], 'errors' => []];

        foreach ($statements as $key => $statement) {
            try {
                $results['responses'][$key] = $this->parseResponse($sqls[$key], $statement);
            } catch (Exception $e) {
                $results['errors'][$key] = $e;
            }
        }

        if (count($results['errors'])) {
            throw new ParallelQueryException($results['responses'], $results['errors']);
        }

        return $results['responses'];
    }

    protected function getDefaultClient(): Client
    {
        $client = new Client([
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'https' => $this->https,
        ]);

        $client->database($this->database);

        // phpclickhouse applies this value both as the cURL timeout and as
        // the server-side max_execution_time, truncated to whole seconds.
        // Round up so a sub-second timeout never collapses to 0 (unlimited).
        if ($this->timeout !== null) {
            $client->setTimeout((int) ceil($this->timeout));
        }

        if ($this->connectTimeout !== null) {
            $client->setConnectTimeOut($this->connectTimeout);
        }

        return $client;
    }

    /** @return array<string, int|string> */
    protected function querySettings(): array
    {
        $settings = ['default_format' => 'JSON'];

        if ($this->session !== null) {
            $settings['session_id'] = $this->session->id;
            $settings['session_timeout'] = $this->session->timeout;
        }

        return $settings;
    }

    protected function parseResponse(string $sql, ClickHouseDBStatement $statement): Response
    {
        if ($statement->isError()) {
            $statement->error();
        }

        // Decoded once and shared by both checks below: result bodies can
        // be large, and each decode is a full pass over them.
        $data = json_decode($statement->getRequest()->response()->body(), true);

        // ClickHouse <= 23 defaults to http_write_exception_in_output_format=1:
        // an error then arrives as HTTP 200 with a valid JSON body whose
        // `exception` field carries the message. phpclickhouse only flags
        // broken JSON, so without this check the error would surface as an
        // empty or silently truncated result.
        $exception = $this->parseJsonExceptionMessage($data);

        if ($exception !== null) {
            throw new QueryException('ClickHouse query error: '.$exception);
        }

        $records = $this->parseRecords($data);

        return new Response(
            $sql,
            $records === null ? $this->parseAffectedRows($statement) : null,
            $records,
        );
    }

    protected function parseAffectedRows(ClickHouseDBStatement $statement): ?int
    {
        $writtenRows = $statement->summary('written_rows');

        return is_numeric($writtenRows) ? (int) $writtenRows : null;
    }

    /**
     * Extract the error message from a decoded ClickHouse JSON body's `exception` field.
     */
    protected function parseJsonExceptionMessage(mixed $data): ?string
    {
        if (! is_array($data) || ! isset($data['exception']) || ! is_string($data['exception'])) {
            return null;
        }

        return $data['exception'];
    }

    /**
     * Extract the result rows from a decoded ClickHouse JSON body.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function parseRecords(mixed $data): ?array
    {
        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return null;
        }

        /** @var array<int, array<string, mixed>> */
        return $data['data'];
    }
}

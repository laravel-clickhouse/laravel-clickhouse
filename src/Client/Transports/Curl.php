<?php

namespace ClickHouse\Client\Transports;

use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Response;
use ClickHouse\Exceptions\ParallelQueryException;
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
    ) {
        $this->client = $client ?? $this->getDefaultClient();
    }

    public function execute(string $sql): Response
    {
        /** @var ClickHouseDBStatement $statement */
        $statement = $this->client->write($sql, querySettings: ['default_format' => 'JSON']);
        $records = $this->parseRecords($statement->getRequest()->response()->body());

        return new Response(
            $sql,
            $records === null ? $this->parseAffectedRows($statement) : null,
            $records,
        );
    }

    public function executeParallelly(array $sqls): array
    {
        $statements = array_map(function ($sql) {
            return $this->client->selectAsync($sql);
        }, $sqls);

        $this->client->executeAsync();

        $results = collect($statements)->reduce(function ($results, $statement, $key) use ($sqls) {
            try {
                $results['responses'][$key] = new Response(
                    $sqls[$key],
                    null,
                    $statement->rows()
                );
            } catch (Exception $e) {
                $results['errors'][$key] = $e;
            }

            return $results;
        }, ['responses' => [], 'errors' => []]);

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

        return $client;
    }

    protected function parseAffectedRows(ClickHouseDBStatement $statement): ?int
    {
        $writtenRows = $statement->summary('written_rows');

        return is_numeric($writtenRows) ? (int) $writtenRows : null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function parseRecords(string $body): ?array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return null;
        }

        return $data['data'];
    }
}

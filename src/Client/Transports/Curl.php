<?php

namespace SwooleTW\ClickHouse\Client\Transports;

use ClickHouseDB\Client;
use ClickHouseDB\Statement as ClickHouseDBStatement;
use Exception;
use SwooleTW\ClickHouse\Client\Contracts\Transport;
use SwooleTW\ClickHouse\Client\Response;
use SwooleTW\ClickHouse\Exceptions\ParallelQueryException;

class Curl implements Transport
{
    protected Client $client;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        ?Client $client = null,
    ) {
        $this->client = $client ?? $this->getDefaultClient();
    }

    public function execute(string $sql): Response
    {
        // FIXME: correct is select condition
        $isSelect = (bool) preg_match('/^(select|with)/i', $sql) || str_contains($sql, ' union ');
        $method = $isSelect ? 'select' : 'write';

        /** @var ClickHouseDBStatement $statement */
        $statement = $this->client->{$method}($sql);

        return new Response(
            $sql,
            $isSelect,
            // FIXME: correct affected rows
            $isSelect ? null : 1,
            $isSelect ? $statement->rows() : null,
        );
    }

    public function executeParallelly(array $sqls): array
    {
        $statements = array_map(function ($sql) {
            return $this->client->selectAsync($sql);
        }, $sqls);

        $this->client->executeAsync();

        $results = collect($statements)->reduce(function ($results, $statement, $key) {
            try {
                $results['results'][$key] = $statement->rows();
            } catch (Exception $e) {
                $results['errors'][$key] = $e;
            }

            return $results;
        }, ['results' => [], 'errors' => []]);

        if (count($results['errors'])) {
            throw new ParallelQueryException($results['results'], $results['errors']);
        }

        return $results['results'];
    }

    protected function getDefaultClient(): Client
    {
        $client = new Client([
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $client->database($this->database);

        return $client;
    }
}

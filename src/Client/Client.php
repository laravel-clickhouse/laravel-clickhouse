<?php

namespace SwooleTW\ClickHouse\Client;

use SwooleTW\ClickHouse\Client\Contracts\Transport;
use SwooleTW\ClickHouse\Exceptions\ParallelQueryException;

class Client
{
    protected TransportFactory $transportFactory;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected string $transport,
        ?TransportFactory $transportFactory = null,
    ) {
        $this->transportFactory = $transportFactory ?? new TransportFactory($host, $port, $database, $username, $password);
    }

    public function exec(string $query): int
    {
        $response = $this->getTransport()->execute($query);

        return $response->getAffectedRows() ?: 0;
    }

    public function prepare(string $query): Statement
    {
        return new Statement($this, $query);
    }

    /**
     * @param  Statement[]  $statements
     *
     * @throws ParallelQueryException<Statement>
     */
    public function parallel(array $statements): void
    {
        $sqls = array_map(function ($statement) {
            return $statement->toRawSql();
        }, $statements);

        try {
            $results = $this->getTransport()->executeParallelly($sqls);
        } catch (ParallelQueryException $e) {
            /** @var ParallelQueryException<array<string, mixed>[]> $e */
            $results = $e->getResults();
            $errors = $e->getErrors();
        }

        foreach ($results as $key => $result) {
            $statements[$key]->setResponse(
                new Response($sqls[$key], true, null, $result)
            );
        }

        if (isset($errors)) {
            throw new ParallelQueryException($statements, $errors);
        }
    }

    public function getTransport(): Transport
    {
        return $this->transportFactory->make($this->transport);
    }

    public function quote(string $string): string
    {
        return sprintf("'%s'", addslashes($string));
    }
}

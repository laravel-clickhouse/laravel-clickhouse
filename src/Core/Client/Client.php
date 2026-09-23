<?php

namespace ClickHouse\Core\Client;

use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Support\Escaper;
use LogicException;

class Client
{
    protected TransportFactory $transportFactory;

    protected Escaper $escaper;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected string $transport,
        protected bool $https = false,
        ?TransportFactory $transportFactory = null,
        ?Escaper $escaper = null,
        protected ?float $timeout = null,
        protected ?float $connectTimeout = null,
    ) {
        $this->transportFactory = $transportFactory ?? new TransportFactory($host, $port, $database, $username, $password, $https, $timeout, $connectTimeout);
        $this->escaper = $escaper ?? new Escaper;
    }

    public function exec(string $query, ?Session $session = null): int
    {
        $response = $this->getTransport($session)->execute($query);

        return $response->getAffectedRows() ?: 0;
    }

    public function prepare(string $query, ?Session $session = null): Statement
    {
        return new Statement($this, $query, $session);
    }

    /**
     * @param  Statement[]  $statements
     *
     * @throws ParallelQueryException<Statement>
     */
    public function parallel(array $statements): void
    {
        // ClickHouse executes at most one query per session at a time, so
        // concurrent queries sharing a session_id would fail server-side
        // with SESSION_IS_LOCKED (code 373).
        foreach ($statements as $statement) {
            if ($statement->getSession() !== null) {
                throw new LogicException('Parallel queries cannot be executed within a ClickHouse session.');
            }
        }

        $sqls = array_map(function ($statement) {
            return $statement->toRawSql();
        }, $statements);

        try {
            $responses = $this->getTransport()->executeParallelly($sqls);
        } catch (ParallelQueryException $e) {
            $responses = $e->getResponses();
            $errors = $e->getErrors();
        }

        foreach ($responses as $key => $response) {
            $statements[$key]->setResponse($response);
        }

        if (isset($errors)) {
            throw new ParallelQueryException($responses, $errors);
        }
    }

    public function getTransport(?Session $session = null): Transport
    {
        return $this->transportFactory->make($this->transport, $session);
    }

    public function getEscaper(): Escaper
    {
        return $this->escaper;
    }
}

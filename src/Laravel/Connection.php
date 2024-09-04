<?php

namespace SwooleTW\ClickHouse\Laravel;

use ClickHouseDB\Client;
use ClickHouseDB\Quote\ValueFormatter;
use Exception;
use Illuminate\Database\Connection as BaseConnection;
use SwooleTW\ClickHouse\Laravel\Query\Grammar;

class Connection extends BaseConnection
{
    /**
     * The ClickHouse client.
     */
    protected Client $client;

    /**
     * Create a new database connection instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(string $database = '', string $tablePrefix = '', array $config = [], ?Client $client = null)
    {
        $this->database = $database;
        $this->tablePrefix = $tablePrefix;
        $this->config = $config;
        $this->client = $client ?? $this->getDefaultClient($database, $config);

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     * @return array<string, mixed>[]
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, function (string $query, array $bindings) {
            $sql = $this->toRawSql($query, $bindings);

            $statement = $this->client->select($sql);

            return $statement->rows();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, function ($query, $bindings) {
            $sql = $this->toRawSql($query, $bindings);

            $this->client->write($sql);

            return true;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, function ($query, $bindings) {
            $sql = $this->toRawSql($query, $bindings);

            $this->client->write($sql);

            // TODO: correct affected rows
            return 1;
        });
    }

    /** {@inheritDoc} */
    public function escape($value, $binary = false): string
    {
        // @phpstan-ignore-next-line
        return (string) ValueFormatter::formatValue($value);
    }

    /** {@inheritDoc} */
    public function reconnectIfMissingConnection() {}

    /** {@inheritDoc} */
    public function disconnect() {}

    /** {@inheritDoc} */
    public function getSchemaBuilder()
    {
        // TODO: implement schema builder
        throw new Exception('Not supported yet');
    }

    /**
     * get the ClickHouse client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /** {@inheritDoc} */
    protected function getDefaultQueryGrammar()
    {
        return (new Grammar)->setConnection($this);
    }

    /** {@inheritDoc} */
    protected function getDefaultSchemaGrammar()
    {
        // TODO: implement schema builder
        throw new Exception('Not supported yet');
    }

    /**
     * Get the default ClickHouse client.
     *
     * @param  array<string, mixed>  $config
     */
    protected function getDefaultClient(string $database, array $config): Client
    {
        $client = new Client($config);

        if ($database) {
            $client->database($database);
        }

        return $client;
    }

    /**
     * Get the raw SQL representation with bindings.
     *
     * @param  mixed[]  $bindings
     */
    protected function toRawSql(string $query, array $bindings): string
    {
        return $this->queryGrammar->substituteBindingsIntoRawSql(
            $query,
            $this->prepareBindings($bindings)
        );
    }
}

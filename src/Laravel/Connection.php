<?php

namespace SwooleTW\ClickHouse\Laravel;

use ClickHouseDB\Quote\ValueFormatter;
use Exception;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\QueryException;
use SwooleTW\ClickHouse\Client\Client;
use SwooleTW\ClickHouse\Client\Statement;
use SwooleTW\ClickHouse\Exceptions\ParallelQueryException;
use SwooleTW\ClickHouse\Laravel\Query\Builder;
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
     * @param  array{
     *     host?: string,
     *     port?: int,
     *     username?: string,
     *     password?: string,
     *     transport?: string,
     * }  $config
     */
    public function __construct(string $database = '', string $tablePrefix = '', array $config = [], ?Client $client = null)
    {
        $this->database = $database ?: 'default';
        $this->tablePrefix = $tablePrefix;
        $this->config = $config;
        $this->client = $client ?? $this->getDefaultClient($database, $config);

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }

    /** {@inheritDoc} */
    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
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
            $statement = $this->client->prepare($query);

            // @phpstan-ignore-next-line
            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->fetchAll();
        });
    }

    /**
     * Run select statements parallelly against the database.
     *
     * @param array<int|string, array{
     *     sql: string,
     *     bindings: mixed[],
     * }> $queries
     * @return array<int|string, array<string, mixed>[]>
     *
     * @throws ParallelQueryException<Statement>
     */
    public function selectParallelly(array $queries): array
    {
        $statements = array_map(function ($query) {
            foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
                $beforeExecutingCallback($query['sql'], $query['bindings'], $this);
            }

            $statement = $this->client->prepare($query['sql']);

            // @phpstan-ignore-next-line
            $this->bindValues($statement, $this->prepareBindings($query['bindings']));

            return $statement;
        }, $queries);

        try {
            $this->client->parallel($statements);
        } catch (ParallelQueryException $e) {
            $errors = collect($e->getErrors())->map(function ($error, $key) use ($queries) {
                return new QueryException(
                    $this->getName() ?: '',
                    $queries[$key]['sql'],
                    $queries[$key]['bindings'],
                    $error
                );
            })->all();

            throw new ParallelQueryException($e->getResults(), $errors);
        }

        return collect($statements)->map(function ($statement, $key) use ($queries) {
            $this->logQuery($queries[$key]['sql'], $queries[$key]['bindings']);

            return $statement->fetchAll() ?: [];
        })->all();
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
            $statement = $this->client->prepare($query);

            // @phpstan-ignore-next-line
            $this->bindValues($statement, $this->prepareBindings($bindings));

            return $statement->execute();
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
            $statement = $this->client->prepare($query);

            // @phpstan-ignore-next-line
            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            return $statement->rowCount();
        });
    }

    /** {@inheritDoc} */
    public function escape($value, $binary = false): string
    {
        // TODO: implement escape
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
     * @param  array{
     *     host?: string,
     *     port?: int,
     *     username?: string,
     *     password?: string,
     *     transport?: string,
     * }  $config
     */
    protected function getDefaultClient(string $database, array $config): Client
    {
        return new Client(
            host: $config['host'] ?? '127.0.0.1',
            port: $config['port'] ?? 8123,
            database: $database,
            username: $config['username'] ?? 'default',
            password: $config['password'] ?? 'default',
            transport: $config['transport'] ?? 'curl',
        );
    }
}

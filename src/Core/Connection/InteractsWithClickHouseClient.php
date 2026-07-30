<?php

namespace ClickHouse\Core\Connection;

use ClickHouse\Client\Client;
use ClickHouse\Client\Statement;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Support\Escaper;

/**
 * ClickHouse HTTP client integration shared by every framework bridge.
 * The using class must extend its framework's database Connection, whose
 * run()/bindValues()/prepareBindings()/logQuery() API this trait relies on.
 */
trait InteractsWithClickHouseClient
{
    /**
     * The ClickHouse client.
     */
    protected Client $client;

    /**
     * The value escaper.
     */
    protected Escaper $escaper;

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
            $errors = [];

            foreach ($e->getErrors() as $key => $error) {
                $errors[$key] = $this->newQueryException(
                    $queries[$key]['sql'],
                    $queries[$key]['bindings'],
                    $error
                );
            }

            throw new ParallelQueryException($e->getResponses(), $errors);
        }

        $results = [];

        foreach ($statements as $key => $statement) {
            $this->logQuery($queries[$key]['sql'], $queries[$key]['bindings']);

            $results[$key] = $statement->fetchAll() ?: [];
        }

        return $results;
    }

    /**
     * Run an insert statement whose rows are streamed in a ClickHouse input
     * format appended after the query, bypassing SQL value escaping. Only
     * the query head is logged, never the data payload.
     */
    public function insertUsingFormat(string $query, string $data): bool
    {
        // @phpstan-ignore-next-line
        return $this->run($query, [], fn (string $query) => $this->runFormattedInsert($query, $data));
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $value
     * @param  bool  $binary
     */
    public function escape($value, $binary = false): string
    {
        return $this->escaper->escape($value, $binary);
    }

    /**
     * Get the ClickHouse client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Create a framework-specific query exception for a failed parallel query.
     */
    protected function newQueryException(string $sql, mixed $bindings, \Throwable $error): \Throwable
    {
        $exception = static::QUERY_EXCEPTION;

        // @phpstan-ignore-next-line
        return new $exception($this->getName() ?: '', $sql, $bindings, $error);
    }

    /**
     * Execute a select statement inside the framework's run() wrapper.
     *
     * @param  mixed[]  $bindings
     * @return array<string, mixed>[]
     */
    protected function executeSelect(string $query, array $bindings): array
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, fn (string $query, array $bindings) => $this->runSelectStatement($query, $bindings));
    }

    /**
     * Execute a statement inside the framework's run() wrapper.
     *
     * @param  mixed[]  $bindings
     */
    protected function executeStatement(string $query, array $bindings): bool
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, fn ($query, $bindings) => $this->runStatement($query, $bindings));
    }

    /**
     * Execute an affecting statement inside the framework's run() wrapper.
     *
     * @param  mixed[]  $bindings
     */
    protected function executeAffectingStatement(string $query, array $bindings): int
    {
        // @phpstan-ignore-next-line
        return $this->run($query, $bindings, fn ($query, $bindings) => $this->runAffectingStatement($query, $bindings));
    }

    /**
     * Run a select statement through the ClickHouse client.
     *
     * @param  mixed[]  $bindings
     * @return array<string, mixed>[]
     */
    protected function runSelectStatement(string $query, array $bindings): array
    {
        $statement = $this->client->prepare($query);

        // @phpstan-ignore-next-line
        $this->bindValues($statement, $this->prepareBindings($bindings));

        $statement->execute();

        // @phpstan-ignore-next-line
        return $statement->fetchAll();
    }

    /**
     * Run a statement through the ClickHouse client.
     *
     * @param  mixed[]  $bindings
     */
    protected function runStatement(string $query, array $bindings): bool
    {
        $statement = $this->client->prepare($query);

        // @phpstan-ignore-next-line
        $this->bindValues($statement, $this->prepareBindings($bindings));

        return $statement->execute();
    }

    /**
     * Run an affecting statement through the ClickHouse client.
     *
     * @param  mixed[]  $bindings
     */
    protected function runAffectingStatement(string $query, array $bindings): int
    {
        $statement = $this->client->prepare($query);

        // @phpstan-ignore-next-line
        $this->bindValues($statement, $this->prepareBindings($bindings));

        $statement->execute();

        // ClickHouse reports no written_rows in X-ClickHouse-Summary for
        // DELETE / ALTER TABLE mutations (verified on 24.x and 25.x), so
        // rowCount() is null there; the framework contract requires an int.
        return $statement->rowCount() ?? 0;
    }

    /**
     * Run an insert whose rows are streamed in a ClickHouse input format
     * appended after the query, bypassing SQL value escaping.
     */
    protected function runFormattedInsert(string $query, string $data): bool
    {
        $this->client->getTransport()->execute($query."\n".$data);

        return true;
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
     *     https?: bool,
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
            transport: $config['transport'] ?? 'guzzle',
            https: $config['https'] ?? false,
        );
    }
}

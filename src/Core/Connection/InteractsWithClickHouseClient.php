<?php

namespace ClickHouse\Core\Connection;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Client\Statement;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Support\Escaper;
use Generator;
use InvalidArgumentException;
use LogicException;
use Throwable;

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
    protected ?Client $client = null;

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
        $client = $this->pretending() ? null : $this->getClient();
        $statements = [];
        $results = [];

        foreach ($queries as $key => $query) {
            foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
                $beforeExecutingCallback($query['sql'], $query['bindings'], $this);
            }

            if ($client === null) {
                $this->logQuery($query['sql'], $query['bindings']);
                $results[$key] = [];

                continue;
            }

            $statement = $client->prepare($query['sql']);

            $this->bindValues($statement, $this->prepareBindings($query['bindings']));

            $statements[$key] = $statement;
        }

        if ($client === null) {
            return $results;
        }

        try {
            $client->parallel($statements);
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

        foreach ($statements as $key => $statement) {
            $this->logQuery($queries[$key]['sql'], $queries[$key]['bindings']);

            $results[$key] = $statement->fetchAll() ?: [];
        }

        return $results;
    }

    /**
     * Run an insert statement whose rows are streamed in a ClickHouse input
     * format appended after the query, bypassing SQL value escaping. Only
     * the query head is logged, never the payload.
     *
     * @internal Plumbing between the query builder's formatted insert and
     *           the transport — not part of the public API. Use the query
     *           builder's insert(..., format:) instead.
     */
    public function insertRawPayload(string $query, string $payload): bool
    {
        // @phpstan-ignore-next-line
        return $this->run($query, [], fn (string $query) => $this->runFormattedInsert($query, $payload));
    }

    /**
     * Run a select statement and yield each result.
     *
     * @param  string  $query
     * @param  mixed[]  $bindings
     * @param  bool  $useReadPdo
     * @param  array<mixed>  $fetchUsing
     * @return Generator<int, array<string, mixed>>
     */
    // Laravel documents PDO cursor rows as stdClass, but ClickHouse returns associative arrays.
    // @phpstan-ignore method.childReturnType
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        foreach ($this->select($query, $bindings, $useReadPdo, $fetchUsing) as $record) {
            yield $record;
        }
    }

    /**
     * Run a raw, unprepared query against the connection.
     *
     * @param  literal-string  $query
     */
    public function unprepared($query): bool
    {
        return $this->statement((string) $query);
    }

    /**
     * Bind values to their positional parameters.
     *
     * @param  mixed  $statement
     * @param  mixed[]  $bindings
     */
    public function bindValues($statement, $bindings): void
    {
        foreach ($bindings as $key => $value) {
            if (! is_int($key)) {
                throw new InvalidArgumentException('ClickHouse only supports positional bindings.');
            }

            /** @var Statement $statement */
            $statement->bindValue($key + 1, $value);
        }
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
     * Get the last insert ID.
     */
    public function getLastInsertId(?string $sequence = null): never
    {
        throw new LogicException('ClickHouse does not support retrieving last insert IDs.');
    }

    /**
     * Get a human-readable name for the connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'ClickHouse';
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        /** @var string $version */
        $version = $this->scalar('SELECT version()');

        return $version;
    }

    /**
     * Get the ClickHouse client.
     */
    public function getClient(): Client
    {
        if (! $this->client instanceof Client) {
            $this->reconnectIfMissingConnection();
        }

        return $this->client
            ?? throw new LogicException('The ClickHouse connection has no client.');
    }

    /**
     * Create a framework-specific query exception for a failed parallel query.
     */
    protected function newQueryException(string $sql, mixed $bindings, Throwable $error): Throwable
    {
        $exception = static::QUERY_EXCEPTION;

        // @phpstan-ignore-next-line
        return new $exception($this->getName(), $sql, $bindings, $error);
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
        if ($this->pretending()) {
            return [];
        }

        $statement = $this->getClient()->prepare($query);

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
        if ($this->pretending()) {
            return true;
        }

        $statement = $this->getClient()->prepare($query);

        $this->bindValues($statement, $this->prepareBindings($bindings));

        $this->recordsHaveBeenModified();

        return $statement->execute();
    }

    /**
     * Run an affecting statement through the ClickHouse client.
     *
     * @param  mixed[]  $bindings
     */
    protected function runAffectingStatement(string $query, array $bindings): int
    {
        if ($this->pretending()) {
            return 0;
        }

        $statement = $this->getClient()->prepare($query);

        $this->bindValues($statement, $this->prepareBindings($bindings));

        $statement->execute();

        // ClickHouse reports no written_rows in X-ClickHouse-Summary for
        // DELETE / ALTER TABLE mutations (verified on 24.x and 25.x), so
        // rowCount() is null there; the framework contract requires an int.
        $count = $statement->rowCount() ?? 0;

        $this->recordsHaveBeenModified($count > 0);

        return $count;
    }

    /**
     * Run an insert whose rows are streamed in a ClickHouse input format
     * appended after the query, bypassing SQL value escaping.
     */
    protected function runFormattedInsert(string $query, string $payload): bool
    {
        if ($this->pretending()) {
            return true;
        }

        $this->recordsHaveBeenModified();

        $this->getClient()->getTransport()->execute($query."\n".$payload);

        return true;
    }

    /**
     * Escape a string value for safe SQL embedding.
     *
     * @param  string  $value
     */
    protected function escapeString($value): string
    {
        return $this->escaper->escapeString($value);
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
     *     connect_timeout?: float|int|numeric-string|null,
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
            connectTimeout: isset($config['connect_timeout']) ? (float) $config['connect_timeout'] : null,
        );
    }
}

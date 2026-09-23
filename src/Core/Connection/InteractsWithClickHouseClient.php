<?php

namespace ClickHouse\Core\Connection;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Client\Session;
use ClickHouse\Core\Client\Statement;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Support\Escaper;
use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * ClickHouse HTTP client integration shared by every framework bridge.
 * The using class must extend its framework's database Connection, whose
 * run()/bindValues()/prepareBindings()/logQuery() API this trait relies on.
 */
trait InteractsWithClickHouseClient
{
    /**
     * Connect timeout in seconds when the config leaves it unset or blank.
     * Matches Hypervel's pool default, so both bridges behave the same
     * regardless of which transport library is in use.
     */
    private const DEFAULT_CONNECT_TIMEOUT = 10.0;

    /**
     * The ClickHouse client.
     */
    protected ?Client $client = null;

    /**
     * The value escaper.
     */
    protected Escaper $escaper;

    /**
     * The HTTP session every query is currently issued under, if any.
     */
    protected ?Session $session = null;

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

            $statement = $client->prepare($query['sql'], $this->session);

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
                $errors[$key] = $this->newParallelQueryException(
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
     * Execute the callback using a ClickHouse HTTP session.
     *
     * The server keeps the session (and its temporary tables) alive until
     * $timeout seconds have passed since its last query; the value must
     * not exceed the server's max_session_timeout setting (3600 by
     * default). Parallel queries are rejected inside a session because
     * ClickHouse executes at most one query per session at a time.
     *
     * @param  Closure(static): mixed  $callback
     */
    public function session(Closure $callback, int $timeout = 60): mixed
    {
        $previousSession = $this->session;
        $this->session = Session::start($timeout);

        try {
            return $callback($this);
        } finally {
            $this->session = $previousSession;
        }
    }

    /**
     * The session the connection is currently issuing queries under, or
     * null outside session().
     */
    public function getSession(): ?Session
    {
        return $this->session;
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
     * DateTimeInterface bindings intentionally stay objects instead of being
     * stringified with the grammar's date format, so the Escaper can choose
     * between a plain 'Y-m-d H:i:s' literal and a toDateTime64(..., 6)
     * expression depending on whether the value carries microseconds.
     *
     * @param  mixed[]  $bindings
     * @return mixed[]
     */
    public function prepareBindings(array $bindings): array
    {
        $dates = array_filter($bindings, fn ($value) => $value instanceof DateTimeInterface);

        return array_replace(parent::prepareBindings($bindings), $dates);
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
     * Wire up the client and escaper around the framework parent's own
     * construction, defaulting the database name for both the wrapper and
     * the client. The bridge constructors delegate here so the assembly
     * logic exists once.
     *
     * @param  array{
     *     host?: string,
     *     port?: int,
     *     username?: string,
     *     password?: string,
     *     transport?: string,
     *     https?: bool,
     *     timeout?: int|float|string|null,
     *     connect_timeout?: int|float|string|null,
     * }  $config
     * @param  callable(string, string, array<string, mixed>): void  $constructParent
     */
    protected function constructClickHouseConnection(string $database, string $tablePrefix, array $config, ?Client $client, ?Escaper $escaper, callable $constructParent): void
    {
        $database = $database ?: 'default';

        $this->client = $client ?? $this->getDefaultClient($database, $config);
        $this->escaper = $escaper ?? new Escaper;

        $constructParent($database, $tablePrefix, $config);
    }

    /**
     * Get the default database driver name. Hypervel's base connection
     * calls this hook natively; the Laravel bridge routes its
     * getDriverName() override here so a missing `driver` config key
     * reports the same name on both bridges.
     */
    protected function getDefaultDriverName(): string
    {
        return 'clickhouse';
    }

    /**
     * Build the framework's schema builder, initialising the default schema
     * grammar first. The builder class comes from the bridge's
     * SCHEMA_BUILDER constant; the bridges' overrides delegate here and
     * only restate their parent's return type.
     */
    protected function createClickHouseSchemaBuilder(): mixed
    {
        // @phpstan-ignore-next-line
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        $builder = static::SCHEMA_BUILDER;

        return new $builder($this);
    }

    /**
     * ClickHouse has no schema dump format, so schema state is rejected
     * with the same exception type and message on every bridge. The
     * bridges' getSchemaState() overrides delegate here — their signatures
     * carry framework-typed Filesystem parameters and cannot be shared.
     */
    protected function throwSchemaDumpingUnsupported(): never
    {
        throw new RuntimeException('Schema dumping is not supported when using ClickHouse.');
    }

    /**
     * Create a framework-specific query exception for a failed parallel query.
     */
    protected function newParallelQueryException(string $sql, mixed $bindings, Throwable $error): Throwable
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

        $statement = $this->getClient()->prepare($query, $this->session);

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

        $statement = $this->getClient()->prepare($query, $this->session);

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

        $statement = $this->getClient()->prepare($query, $this->session);

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

        $this->getClient()->getTransport($this->session)->execute($query."\n".$payload);

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
     *     timeout?: int|float|string|null,
     *     connect_timeout?: int|float|string|null,
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
            timeout: $this->parseTimeout($config, 'timeout'),
            connectTimeout: $this->parseTimeout($config, 'connect_timeout') ?? self::DEFAULT_CONNECT_TIMEOUT,
        );
    }

    /**
     * Normalize a timeout config value, which may arrive as a numeric
     * string when read from the environment. An empty string (a blank
     * env variable) is treated as not configured.
     *
     * @param  array<string, mixed>  $config
     */
    private function parseTimeout(array $config, string $key): ?float
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("The [{$key}] config value must be numeric.");
        }

        return (float) $value;
    }
}

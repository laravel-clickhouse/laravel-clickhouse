<?php

namespace ClickHouse\Hypervel;

use ClickHouse\Client\Client;
use ClickHouse\Core\Connection\InteractsWithClickHouseClient;
use ClickHouse\Core\Connection\RejectsTransactions;
use ClickHouse\Core\Contracts\ClickHouseConnection;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use ClickHouse\Hypervel\Query\Grammar as QueryGrammar;
use ClickHouse\Hypervel\Schema\Builder as SchemaBuilder;
use ClickHouse\Hypervel\Schema\Grammar as SchemaGrammar;
use ClickHouse\Support\Escaper;
use Generator;
use Hypervel\Database\Connection as BaseConnection;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\SchemaState;
use Hypervel\Filesystem\Filesystem;
use PDO;
use RuntimeException;
use Throwable;

class Connection extends BaseConnection implements ClickHouseConnection
{
    use InteractsWithClickHouseClient;
    use RejectsTransactions;

    protected const QUERY_EXCEPTION = QueryException::class;

    /**
     * Create a new database connection instance.
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
    public function __construct(string $database = '', string $tablePrefix = '', array $config = [], ?Client $client = null, ?Escaper $escaper = null)
    {
        $this->client = $client ?? $this->getDefaultClient($database ?: 'default', $config);
        $this->escaper = $escaper ?? new Escaper;

        parent::__construct(
            static function (): never {
                throw new RuntimeException('ClickHouse connections do not use PDO; use getClient() instead.');
            },
            $database ?: 'default',
            $tablePrefix,
            $config
        );
    }

    /** {@inheritDoc} */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     * @param  array<mixed>  $fetchUsing
     * @return array<string, mixed>[]
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->executeSelect($query, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     * @param  array<mixed>  $fetchUsing
     * @return Generator<int, array<string, mixed>>
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        foreach ($records as $record) {
            yield $record;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->executeStatement($query, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->executeAffectingStatement($query, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * The parent natively type-hints PDOStatement; the ClickHouse bridge
     * binds values onto the HTTP client's Statement instead, so the
     * parameter is widened here.
     *
     * @param  mixed  $statement
     * @param  mixed[]  $bindings
     */
    public function bindValues($statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value
            );
        }
    }

    /** {@inheritDoc} */
    public function getPdo(): PDO
    {
        throw new RuntimeException('ClickHouse connections do not use PDO; use getClient() instead.');
    }

    /** {@inheritDoc} */
    public function getReadPdo(): PDO
    {
        throw new RuntimeException('ClickHouse connections do not use PDO; use getClient() instead.');
    }

    /** {@inheritDoc} */
    public function reconnect(): mixed
    {
        $this->client = $this->getDefaultClient($this->database, $this->getClickHouseConfig());

        return null;
    }

    /** {@inheritDoc} */
    public function reconnectIfMissingConnection(): void {}

    /** {@inheritDoc} */
    public function disconnect(): void {}

    /**
     * Determine whether the ClickHouse server is reachable.
     */
    public function ping(): bool
    {
        try {
            $statement = $this->client->prepare('SELECT 1');
            $statement->execute();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** {@inheritDoc} */
    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection.
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): SchemaState
    {
        throw new RuntimeException('Schema dumping is not supported when using ClickHouse.');
    }

    /** {@inheritDoc} */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    /** {@inheritDoc} */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar($this);
    }

    /**
     * Narrow the untyped config array to the ClickHouse client options.
     *
     * @return array{
     *     host?: string,
     *     port?: int,
     *     username?: string,
     *     password?: string,
     *     transport?: string,
     *     https?: bool,
     * }
     */
    protected function getClickHouseConfig(): array
    {
        // @phpstan-ignore-next-line
        return $this->config;
    }
}

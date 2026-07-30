<?php

namespace ClickHouse\Laravel;

use ClickHouse\Client\Client;
use ClickHouse\Core\Connection\InteractsWithClickHouseClient;
use ClickHouse\Core\Contracts\ClickHouseConnection;
use ClickHouse\Laravel\Query\Builder as QueryBuilder;
use ClickHouse\Laravel\Query\Grammar as QueryGrammar;
use ClickHouse\Laravel\Schema\Builder as SchemaBuilder;
use ClickHouse\Laravel\Schema\Grammar as SchemaGrammar;
use ClickHouse\Support\Escaper;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\QueryException;
use LogicException;
use RuntimeException;

class Connection extends BaseConnection implements ClickHouseConnection
{
    use InteractsWithClickHouseClient;

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
        $this->database = $database ?: 'default';
        $this->tablePrefix = $tablePrefix;
        $this->config = $config;
        $this->client = $client ?? $this->getDefaultClient($database, $config);
        $this->escaper = $escaper ?? new Escaper;

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }

    /** {@inheritDoc} */
    public function query()
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
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->executeSelect($query, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        return $this->executeStatement($query, $bindings);
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        return $this->executeAffectingStatement($query, $bindings);
    }

    /** {@inheritDoc} */
    public function reconnectIfMissingConnection() {}

    /** {@inheritDoc} */
    public function disconnect() {}

    /**
     * {@inheritDoc}
     *
     * @param  Closure(static): mixed  $callback
     *
     * @throws LogicException
     */
    public function transaction(Closure $callback, $attempts = 1): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LogicException
     */
    public function beginTransaction(): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LogicException
     */
    public function commit(): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LogicException
     */
    public function rollBack($toLevel = null): never
    {
        $this->throwUnsupportedTransaction();
    }

    /** {@inheritDoc} */
    public function getSchemaBuilder()
    {
        // @phpstan-ignore-next-line
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection.
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): never
    {
        throw new RuntimeException('Schema dumping is not supported when using ClickHouse.');
    }

    /** {@inheritDoc} */
    protected function getDefaultQueryGrammar()
    {
        return $this->makeGrammar(QueryGrammar::class);
    }

    /** {@inheritDoc} */
    protected function getDefaultSchemaGrammar()
    {
        $grammar = $this->makeGrammar(SchemaGrammar::class);

        // withTablePrefix() only exists on Laravel 11; from Laravel 12 the
        // grammar reads the prefix off its injected connection.
        if (method_exists($this, 'withTablePrefix')) {
            return $this->withTablePrefix($grammar);
        }

        return $grammar;
    }

    private function throwUnsupportedTransaction(): never
    {
        throw new LogicException('Transactions are not supported when using ClickHouse.');
    }

    /**
     * Instantiate a grammar, passing the connection through whichever API
     * the active Laravel version exposes. On 12+ the grammar accepts the
     * connection via its constructor; on 11 the argument is silently
     * ignored by the implicit constructor and setConnection() is used.
     *
     * @template TGrammar of object
     *
     * @param  class-string<TGrammar>  $class
     * @return TGrammar
     */
    private function makeGrammar(string $class): object
    {
        $grammar = new $class($this);

        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
        }

        return $grammar;
    }
}

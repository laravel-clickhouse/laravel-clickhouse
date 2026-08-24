<?php

namespace ClickHouse\Hypervel;

use ClickHouse\Core\Client\Client;
use ClickHouse\Core\Connection\InteractsWithClickHouseClient;
use ClickHouse\Core\Connection\RejectsTransactions;
use ClickHouse\Core\Contracts\ClickHouseConnection;
use ClickHouse\Core\Support\Escaper;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use ClickHouse\Hypervel\Query\Grammar as QueryGrammar;
use ClickHouse\Hypervel\Schema\Builder as SchemaBuilder;
use ClickHouse\Hypervel\Schema\Grammar as SchemaGrammar;
use Hypervel\Database\Connection as BaseConnection;
use Hypervel\Database\QueryException;
use Hypervel\Filesystem\Filesystem;
use LogicException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
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
     *     connect_timeout?: float|int|numeric-string|null,
     * }  $config
     */
    public function __construct(string $database = '', string $tablePrefix = '', array $config = [], ?Client $client = null, ?Escaper $escaper = null)
    {
        $database = $database ?: 'default';

        $this->client = $client ?? $this->getDefaultClient($database, $config);
        $this->escaper = $escaper ?? new Escaper;

        parent::__construct($database, $tablePrefix, $config);
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
     * Determine whether the ClickHouse server is reachable.
     */
    public function ping(): bool
    {
        if (! $this->client instanceof Client) {
            return false;
        }

        try {
            $statement = $this->client->prepare('SELECT 1');
            $statement->execute();

            return true;
        } catch (CanceledException $exception) {
            throw $exception;
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
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): never
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
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'clickhouse';
    }

    /**
     * Determine whether the connection has driver resources.
     */
    protected function hasDriverResources(): bool
    {
        return $this->client instanceof Client;
    }

    /**
     * Disconnect the driver resources.
     */
    protected function disconnectDriverResources(): void
    {
        // The logical client owns no persistent transport to close.
        $this->forgetDriverResources();
    }

    /**
     * Forget the driver resources without performing physical cleanup.
     */
    protected function forgetDriverResources(): void
    {
        $this->client = null;
    }

    /**
     * Refresh the driver resources from a fresh connection.
     */
    protected function replaceDriverResources(BaseConnection $fresh): void
    {
        /** @var self $fresh */
        if (! $fresh->client instanceof Client) {
            throw new LogicException('The fresh ClickHouse connection has no client.');
        }

        $client = $fresh->client;
        $database = $fresh->database;
        $configuredDatabase = $fresh->configuredDatabase;
        $tablePrefix = $fresh->tablePrefix;
        $configuredTablePrefix = $fresh->configuredTablePrefix;
        $config = $fresh->config;
        $readConnectionConfig = $fresh->readConnectionConfig;
        $readWriteType = $fresh->readWriteType;

        try {
            $this->disconnect();
        } finally {
            // A cleanup failure must not leave the old resource generation attached.
            $this->client = $client;
            $this->database = $database;
            $this->configuredDatabase = $configuredDatabase;
            $this->tablePrefix = $tablePrefix;
            $this->configuredTablePrefix = $configuredTablePrefix;
            $this->config = $config;
            $this->readConnectionConfig = $readConnectionConfig;
            $this->readWriteType = $readWriteType;
            $this->latestReadWriteTypeRetrieved = null;
        }
    }
}

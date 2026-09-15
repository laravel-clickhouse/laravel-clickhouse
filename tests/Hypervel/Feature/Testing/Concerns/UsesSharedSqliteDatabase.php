<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\Concerns;

use PDO;

/**
 * Keep the SQLite database available while database transactions are
 * combined with ClickHouse truncation.
 *
 * SQLite is excluded from the truncation targets in this scenario, so
 * Hypervel cannot retain its in-memory PDO through DatabaseTruncation.
 * The shared-cache URI and keepalive PDO preserve the schema while
 * DatabaseTransactions rolls back each test.
 */
trait UsesSharedSqliteDatabase
{
    /**
     * Long-lived PDO that keeps the shared in-memory database alive for
     * the entire test run. Lazily opened on the first `defineEnvironment`
     * call and left in place — process exit releases it.
     */
    protected static ?PDO $sqliteKeepalive = null;

    protected string $sqliteUri = 'file:testing?mode=memory&cache=shared';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        static::$sqliteKeepalive ??= new PDO('sqlite:'.$this->sqliteUri);

        $app->make('config')->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->sqliteUri,
        ]);
    }
}

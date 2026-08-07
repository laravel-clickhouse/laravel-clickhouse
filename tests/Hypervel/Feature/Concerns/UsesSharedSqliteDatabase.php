<?php

namespace ClickHouse\Tests\Hypervel\Feature\Concerns;

use PDO;

/**
 * Switches the demo `sqlite` connection from the base TestCase's bare
 * `:memory:` to a shared-cache in-memory database that survives the
 * per-test pool flush.
 *
 * Only truncation-based scenarios need this: DatabaseTruncation runs
 * `migrate:fresh` once and then assumes the schema persists, but the
 * testing lifecycle flushes the connection pools between tests, and a
 * bare `:memory:` private database dies with its PDO (the framework's
 * in-memory PDO preservation only kicks in for RefreshDatabase). A URI
 * with `mode=memory&cache=shared` lets every PDO opened in this process
 * join the same in-memory DB, and a keepalive PDO holds the cache alive
 * across flushes.
 *
 * Unlike the Laravel counterpart, no custom driver is needed: Hypervel's
 * SQLiteConnector passes `file:` URIs through untouched, and its
 * SQLiteBuilder routes in-memory schemas (empty path in PRAGMA
 * database_list) through SQL drops rather than file truncation.
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

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->sqliteUri,
        ]);
    }
}

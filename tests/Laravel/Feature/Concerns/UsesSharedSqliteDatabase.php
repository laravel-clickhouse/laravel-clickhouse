<?php

namespace ClickHouse\Tests\Laravel\Feature\Concerns;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use PDO;

/**
 * Switches the demo `sqlite` connection from the base TestCase's bare
 * `:memory:` to a shared-cache in-memory database that survives Laravel's
 * reconnects between tests.
 *
 * Only truncation-based scenarios need this: DatabaseTruncation runs
 * `migrate:fresh` once and then assumes the schema persists, but a bare
 * `:memory:` private database dies the moment its only PDO disconnects,
 * and Laravel's in-memory PDO preservation only kicks in for
 * RefreshDatabase. A URI with `mode=memory&cache=shared` lets every PDO
 * opened in this process join the same in-memory DB, and a keepalive PDO
 * holds the cache alive across reconnects.
 *
 * Why the named form (`file:testing?mode=memory&cache=shared`) and not
 * the simpler `file::memory:?cache=shared`: Laravel's
 * `Schema\SQLiteBuilder::dropAllTables()` decides whether to drop tables
 * via SQL or just `file_put_contents('', $database)` by text-matching the
 * database string for `:memory:`, `?mode=memory`, or `&mode=memory`.
 * `file::memory:?cache=shared` matches none of those substrings (it has
 * `:memory:` inside but not equal to it), so `db:wipe` would silently
 * truncate a literal file in the cwd instead of dropping the in-memory
 * tables — leaving stale data across `migrate:fresh` calls. The named
 * form satisfies the `?mode=memory` substring check and routes through
 * the SQL path.
 *
 * This setup is purely a demo concern. Application code consuming the
 * package's traits does not need it — bare `:memory:` is fine for
 * RefreshDatabase and DatabaseMigrations, and any persistent database
 * (file SQLite, MySQL, …) works for DatabaseTruncation.
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

    protected function openSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->sqliteUri);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Open the keepalive lazily on the first env definition so the
        // shared in-memory cache survives Laravel's reconnects between
        // tests. `??=` keeps it process-wide — opened once, reused after.
        static::$sqliteKeepalive ??= $this->openSqlitePdo();

        // Custom sqlite driver wired for the demo testbench. It opens a
        // PDO directly against the shared-cache in-memory URI so each
        // Laravel-side reconnect lands on the same in-memory DB that the
        // keepalive PDO is holding. Wiring our own driver keeps the demo's
        // SQLite layer self-contained and independent of any stock-driver
        // behaviour around URI-mode database strings.
        $app->resolving('db', function ($db) {
            $db->extend('sqlite_memory_shared', fn (array $config, string $name) => new SQLiteConnection(
                fn (): PDO => $this->openSqlitePdo(),
                $config['database'],
                $config['prefix'] ?? '',
                array_merge($config, ['name' => $name]),
            ));
        });

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite_memory_shared',
            'database' => $this->sqliteUri,
        ]);
    }
}

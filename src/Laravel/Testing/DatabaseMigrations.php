<?php

namespace ClickHouse\Laravel\Testing;

use Illuminate\Foundation\Testing\DatabaseMigrations as BaseDatabaseMigrations;

/**
 * Drop-in replacement for the framework's DatabaseMigrations trait that
 * keeps multi-connection setups clean across test classes.
 *
 * Each migration declares its target connection via `protected $connection`,
 * and `migrate:fresh` re-runs every registered migration on each invocation
 * — so a single test class can land tables on several connections at once.
 * `migrate:fresh --database=X` only drops tables on X (and even then only
 * when the migrations table already exists on X), so any other connection a
 * migration touches keeps its tables. Across test classes those leftover
 * tables stack up and the next `CREATE TABLE` collides.
 *
 * List every connection the class's migrations target via
 * `$connectionsToMigrate` (mirroring `$connectionsToTruncate` on
 * DatabaseTruncation). Each one is wiped with `db:wipe` before the standard
 * `migrate:fresh` runs, so every relevant connection starts empty.
 */
trait DatabaseMigrations
{
    use BaseDatabaseMigrations;

    /**
     * Perform any work that should take place before the database has started refreshing.
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        $this->wipeAdditionalConnections();
    }

    /**
     * Wipe the additional connections.
     */
    protected function wipeAdditionalConnections(): void
    {
        $connections = $this->connectionsToMigrate();

        foreach ($connections as $connection) {
            $this->artisan('db:wipe', ['--database' => $connection]);
        }
    }

    /**
     * Get the connections that should be wiped before `migrate:fresh` runs.
     *
     * When `$connectionsToMigrate` is not declared, fall back to a single
     * `null` entry — `db:wipe` resolves a null `--database` back to the
     * default connection from config. `migrate:fresh --database=<default>`
     * would normally wipe it itself, but only when the migrations table
     * already exists on that connection — and across test classes the
     * connection that is the default *now* may have been a secondary in an
     * earlier class, leaving tables behind without ever holding the
     * migrations table. Wiping the default unconditionally covers that
     * cross-class leftover.
     *
     * @return array<int, string|null>
     */
    protected function connectionsToMigrate(): array
    {
        /** @var array<int, string|null> $connectionsToMigrate */
        $connectionsToMigrate = property_exists($this, 'connectionsToMigrate')
            ? $this->connectionsToMigrate
            : [null];

        return $connectionsToMigrate;
    }
}

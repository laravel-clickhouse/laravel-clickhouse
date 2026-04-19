<?php

namespace ClickHouse\Laravel\Testing;

use Illuminate\Foundation\Testing\DatabaseMigrations as BaseDatabaseMigrations;

/**
 * Drop-in replacement for the framework's DatabaseMigrations trait that
 * supports running migrations across multiple connections in a single test
 * class.
 *
 * `migrate:fresh` only drops tables on the connection passed via --database,
 * but always re-runs every registered migration (each landing on the
 * connection it declares via $connection). When migrations target more than
 * one connection, secondary connections accumulate tables across test
 * classes and CREATE TABLE eventually conflicts.
 *
 * Declare the secondary connections via `$connectionsToMigrate` (mirroring
 * `$connectionsToTruncate` on DatabaseTruncation) and they will be wiped via
 * `db:wipe` before the standard migrate:fresh.
 */
trait DatabaseMigrations
{
    use BaseDatabaseMigrations {
        refreshTestDatabase as protected baseRefreshTestDatabase;
    }

    /**
     * @return void
     */
    protected function refreshTestDatabase()
    {
        $this->wipeAdditionalConnections();

        $this->baseRefreshTestDatabase();
    }

    private function wipeAdditionalConnections(): void
    {
        $default = $this->app['config']->get('database.default');

        /** @var array<int, string> $connections */
        $connections = property_exists($this, 'connectionsToMigrate')
            ? $this->connectionsToMigrate
            : [];

        foreach ($connections as $connection) {
            if ($connection === $default) {
                continue;
            }

            $this->artisan('db:wipe', ['--database' => $connection]);
        }
    }
}

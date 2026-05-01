<?php

namespace ClickHouse\Laravel\Testing;

use Illuminate\Foundation\Testing\DatabaseTruncation as BaseDatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's DatabaseTruncation trait that
 * supports running migrations across multiple connections in a single test
 * class.
 *
 * The framework's trait runs `migrate:fresh` on the default connection on
 * first setup, but `migrate:fresh` always re-runs every registered migration
 * (each landing on the connection it declares via $connection). When
 * migrations target more than one connection, secondary connections
 * accumulate tables across test classes and CREATE TABLE eventually
 * conflicts.
 *
 * Declare the secondary connections via `$connectionsToMigrate` (mirroring
 * `$connectionsToTruncate`) and they will be wiped via `db:wipe` before the
 * one-time `migrate:fresh`.
 */
trait DatabaseTruncation
{
    use BaseDatabaseTruncation {
        beforeTruncatingDatabase as protected baseBeforeTruncatingDatabase;
    }

    protected function beforeTruncatingDatabase(): void
    {
        $this->baseBeforeTruncatingDatabase();

        if (RefreshDatabaseState::$migrated) {
            return;
        }

        $default = $this->app['config']->get('database.default');

        /** @var array<int, string> $extra */
        $extra = property_exists($this, 'connectionsToMigrate')
            ? $this->connectionsToMigrate
            : [];

        // Always include the default connection. migrate:fresh's internal
        // db:wipe only triggers when the migrations table exists on that
        // connection, so a connection populated by another connection's
        // migrations (e.g. ch_events on ClickHouse when migrations table
        // lives on SQLite) is missed and CREATE TABLE collides on the next
        // run. Wiping unconditionally avoids that leftover.
        $connections = array_values(array_unique([$default, ...$extra]));

        foreach ($connections as $connection) {
            $this->artisan('db:wipe', ['--database' => $connection]);
        }
    }
}

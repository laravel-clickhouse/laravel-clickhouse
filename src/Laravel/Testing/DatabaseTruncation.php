<?php

namespace ClickHouse\Laravel\Testing;

use Illuminate\Foundation\Testing\DatabaseTruncation as BaseDatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's DatabaseTruncation trait that
 * keeps multi-connection setups clean across test classes.
 *
 * On first setup `DatabaseTruncation` runs `migrate:fresh` to build the
 * schema, then truncates tables between subsequent tests. Each migration
 * declares its target connection via `protected $connection`, and
 * `migrate:fresh` re-runs every registered migration on each invocation
 * — so a single test class can land tables on several connections at once.
 * `migrate:fresh --database=X` only drops tables on X (and even then only
 * when the migrations table already exists on X), so any other connection
 * a migration touches keeps its tables. Across test classes those leftover
 * tables stack up and the next `CREATE TABLE` collides.
 *
 * List every connection the class's migrations target via
 * `$connectionsToTruncate` (the same property used for between-test
 * truncation; a null entry resolves to the default connection). Each one
 * is wiped with `db:wipe` before the one-time `migrate:fresh` runs, so
 * every relevant connection starts empty.
 */
trait DatabaseTruncation
{
    use BaseDatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // The framework calls this hook on every test setup, but its
        // `migrate:fresh` only runs once per class (gated by
        // RefreshDatabaseState::$migrated). The pre-wipe is only meaningful
        // before that one `migrate:fresh` — subsequent calls short-circuit,
        // and truncation between tests handles the rest.
        if (RefreshDatabaseState::$migrated) {
            return;
        }

        foreach ($this->connectionsToTruncate() as $connection) {
            $this->artisan('db:wipe', ['--database' => $connection]);
        }
    }
}

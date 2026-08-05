<?php

namespace ClickHouse\Core\Testing;

/**
 * The `db:wipe` pre-pass shared by every framework bridge's testing traits,
 * and the single place documenting why that pre-pass exists.
 *
 * Each migration declares its target connection via `protected $connection`,
 * and `migrate:fresh` re-runs every registered migration on each invocation
 * — so a single test class can land tables on several connections at once.
 * `migrate:fresh --database=X` only drops tables on X (and even then only
 * when the migrations table already exists on X), so any other connection a
 * migration touches keeps its tables. Across test classes those leftover
 * tables stack up and the next `CREATE TABLE` collides. The bridge traits
 * therefore wipe every connection the class works with before the
 * framework's `migrate:fresh`, each strategy picking its wipe targets and
 * cadence through the methods below:
 *
 * - RefreshDatabase — targets from {@see connectionsToRefresh()}, wiped
 *   once per process via {@see wipeConnectionsBeforeFirstMigration()}.
 *   Stack it with the DatabaseTruncation trait ("hybrid" isolation, see
 *   docs/testing): rollback resets the transacting connections per test,
 *   truncation resets ClickHouse per test. On its own it wipes nothing for
 *   ClickHouse — the targets are derived from the declared trait lists.
 * - DatabaseMigrations — targets from {@see connectionsToMigrate()}, wiped
 *   before every test via {@see wipeConnections()}: the framework trait
 *   reruns `migrate:fresh` per test (its teardown `migrate:rollback`
 *   resets the RefreshDatabaseState flag), so the pre-wipe must rerun too.
 * - DatabaseTruncation — targets from the framework's own
 *   `connectionsToTruncate()`, wiped once per process via
 *   {@see wipeConnectionsBeforeFirstMigration()}; between-test resets are
 *   handled by the truncation itself.
 *
 * The using trait must also use its framework's testing trait, whose
 * artisan() API this trait relies on, and bind the framework's
 * RefreshDatabaseState via the REFRESH_DATABASE_STATE constant.
 */
trait WipesClickHouseConnections
{
    /**
     * Wipe every listed connection via `db:wipe`. A null entry resolves to
     * the default connection from config.
     *
     * @param  array<int, string|null>  $connections
     */
    protected function wipeConnections(array $connections): void
    {
        foreach ($connections as $connection) {
            $this->artisan('db:wipe', ['--database' => $connection]);
        }
    }

    /**
     * Wipe the given connections unless the one-time `migrate:fresh` has
     * already run. The framework calls the before-hooks on every test
     * setup, but `migrate:fresh` only runs once per process (gated by
     * RefreshDatabaseState::$migrated) — the pre-wipe is only meaningful
     * before that one run.
     *
     * @param  array<int, string|null>  $connections
     */
    protected function wipeConnectionsBeforeFirstMigration(array $connections): void
    {
        $state = static::REFRESH_DATABASE_STATE;

        if ($state::$migrated) {
            return;
        }

        $this->wipeConnections($connections);
    }

    /**
     * Get the connections that should be wiped before `migrate:fresh` runs
     * under the RefreshDatabase strategy.
     *
     * Derived instead of declared: every connection the class works with is
     * already listed for its per-test reset strategy — `$connectionsToTransact`
     * (rollback) or, in the hybrid stacking, `$connectionsToTruncate`
     * (truncation) — and the union of the two is exactly the set the
     * migrations build schema on. A missing `$connectionsToTransact` falls
     * back to the framework's own default (the default connection), keeping
     * the wipe target aligned with what the framework transacts.
     *
     * @return array<int, string|null>
     */
    protected function connectionsToRefresh(): array
    {
        // Read the property rather than calling connectionsToTruncate():
        // that method only exists when the class also uses the truncation
        // trait (standalone usage would fatal), and its [null] fallback
        // means "truncate the default connection" — here a missing property
        // means "no connection uses the truncation strategy", i.e. [].
        /** @var array<int, string|null> $connectionsToTruncate */
        $connectionsToTruncate = property_exists($this, 'connectionsToTruncate')
            ? $this->connectionsToTruncate
            : [];

        return array_values(array_unique([...$this->connectionsToTransact(), ...$connectionsToTruncate]));
    }

    /**
     * Get the connections that should be wiped before `migrate:fresh` runs
     * under the DatabaseMigrations strategy.
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

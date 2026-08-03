<?php

namespace ClickHouse\Laravel\Testing;

use ClickHouse\Laravel\Testing\Concerns\WipesConnections;
use Illuminate\Foundation\Testing\RefreshDatabase as BaseRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's RefreshDatabase trait that keeps
 * multi-connection setups clean across test runs.
 *
 * The framework trait runs `migrate:fresh` once per process and wraps each
 * test in a transaction on the `$connectionsToTransact` connections. That
 * `migrate:fresh` only wipes the default connection, but the migrations it
 * re-runs build schema on every connection they declare — so ClickHouse
 * tables left by an earlier run survive and collide on `CREATE TABLE`.
 * This trait pre-wipes every connection the class works with before that
 * one-time `migrate:fresh`.
 *
 * Use it together with the package's DatabaseTruncation trait ("hybrid"
 * isolation, see docs/testing): rollback resets the transacting connections
 * per test, truncation resets ClickHouse per test — the same cadence on
 * every connection, and bare `:memory:` SQLite works because the framework
 * preserves its PDO between tests.
 *
 * On its own this trait behaves like the framework's on the transacting
 * connections and nothing more — the wipe targets are derived from the
 * declared trait lists, so without the truncation trait ClickHouse is
 * neither wiped nor isolated. Whenever migrations or tests touch
 * ClickHouse, stack the truncation trait.
 */
trait RefreshDatabase
{
    use BaseRefreshDatabase;
    use WipesConnections;

    /**
     * Perform any work that should take place before the database has started refreshing.
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        // The framework calls this hook on every test setup, but its
        // `migrate:fresh` only runs once per process (gated by
        // RefreshDatabaseState::$migrated). The pre-wipe is only meaningful
        // before that one `migrate:fresh` — subsequent calls short-circuit.
        if (RefreshDatabaseState::$migrated) {
            return;
        }

        $this->wipeConnections($this->connectionsToRefresh());
    }

    /**
     * Get the connections that should be wiped before `migrate:fresh` runs.
     *
     * Derived instead of declared: every connection the class works with is
     * already listed for its per-test reset strategy — `$connectionsToTransact`
     * (rollback) or, in the hybrid stacking, `$connectionsToTruncate`
     * (truncation) — and the union of the two is exactly the set the
     * migrations build schema on. A missing `$connectionsToTransact` falls
     * back to the framework's own default (the default connection), keeping
     * this trait's wipe target aligned with what the framework transacts.
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
}

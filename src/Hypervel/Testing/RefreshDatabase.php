<?php

namespace ClickHouse\Hypervel\Testing;

use ClickHouse\Core\Testing\WipesClickHouseConnections;
use Hypervel\Foundation\Testing\RefreshDatabase as BaseRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's RefreshDatabase trait that
 * pre-wipes every connection the class works with before the one-time
 * `migrate:fresh` — see {@see WipesClickHouseConnections} for why and for
 * how the wipe targets are derived.
 */
trait RefreshDatabase
{
    use BaseRefreshDatabase;
    use WipesClickHouseConnections;

    protected const REFRESH_DATABASE_STATE = RefreshDatabaseState::class;

    /** {@inheritDoc} */
    protected function beforeRefreshingDatabase(): void
    {
        $this->wipeConnectionsBeforeFirstMigration($this->connectionsToRefresh());
    }
}

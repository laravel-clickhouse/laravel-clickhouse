<?php

namespace ClickHouse\Hypervel\Testing;

use ClickHouse\Core\Testing\WipesClickHouseConnections;
use Hypervel\Foundation\Testing\DatabaseMigrations as BaseDatabaseMigrations;
use Hypervel\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's DatabaseMigrations trait that
 * pre-wipes the `$connectionsToMigrate` connections before every
 * `migrate:fresh` — see {@see WipesClickHouseConnections} for why and for
 * the per-test cadence this strategy requires.
 */
trait DatabaseMigrations
{
    use BaseDatabaseMigrations;
    use WipesClickHouseConnections;

    protected const REFRESH_DATABASE_STATE = RefreshDatabaseState::class;

    /** {@inheritDoc} */
    protected function beforeRefreshingDatabase(): void
    {
        $this->wipeConnections($this->connectionsToMigrate());
    }
}

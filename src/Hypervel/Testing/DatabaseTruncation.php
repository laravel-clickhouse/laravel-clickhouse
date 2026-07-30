<?php

namespace ClickHouse\Hypervel\Testing;

use ClickHouse\Core\Testing\WipesClickHouseConnections;
use Hypervel\Foundation\Testing\DatabaseTruncation as BaseDatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabaseState;

/**
 * Drop-in replacement for the framework's DatabaseTruncation trait that
 * pre-wipes the `$connectionsToTruncate` connections before the one-time
 * `migrate:fresh` — see {@see WipesClickHouseConnections} for why.
 */
trait DatabaseTruncation
{
    use BaseDatabaseTruncation;
    use WipesClickHouseConnections;

    protected const REFRESH_DATABASE_STATE = RefreshDatabaseState::class;

    /** {@inheritDoc} */
    protected function beforeTruncatingDatabase(): void
    {
        $this->wipeConnectionsBeforeFirstMigration($this->connectionsToTruncate());
    }
}

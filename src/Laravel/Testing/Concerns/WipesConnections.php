<?php

namespace ClickHouse\Laravel\Testing\Concerns;

trait WipesConnections
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
}

<?php

namespace ClickHouse\Hypervel;

use ClickHouse\Hypervel\Migrations\DatabaseMigrationRepository;
use Hypervel\Support\ServiceProvider;

class ClickHouseServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->make('db')->extend(
            'clickhouse',
            static fn (array $config, ?string $name): Connection => new Connection(
                database: $config['database'] ?? 'default',
                tablePrefix: $config['prefix'],
                config: $config,
            ),
        );

        $this->app->singleton('migration.repository', function ($app) {
            $migrations = $app->make('config')->get('database.migrations');

            $table = is_array($migrations)
                ? ($migrations['table'] ?? 'migrations')
                : $migrations;

            return new DatabaseMigrationRepository($app->make('db'), $table);
        });
    }
}

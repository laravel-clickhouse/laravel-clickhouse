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
        Connection::resolverFor('clickhouse', static function ($pdo, string $database, string $tablePrefix, array $config) {
            // @phpstan-ignore-next-line
            return new Connection($database, $tablePrefix, $config);
        });

        $this->app->singleton('migration.repository', function ($app) {
            $migrations = $app['config']['database.migrations'];

            $table = is_array($migrations)
                ? ($migrations['table'] ?? 'migrations')
                : $migrations;

            return new DatabaseMigrationRepository($app['db'], $table);
        });
    }
}

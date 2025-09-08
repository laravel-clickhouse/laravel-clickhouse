<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Console\Migrations\InstallCommand as MigrateInstallCommand;
use Illuminate\Support\ServiceProvider;

class ClickHouseServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        // @phpstan-ignore-next-line
        Model::setConnectionResolver($this->app['db']);

        // @phpstan-ignore-next-line
        Model::setEventDispatcher($this->app['events']);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->resolving('db', function ($db) {
            $db->extend('clickhouse', static function ($config, $name) {
                return new Connection(
                    database: $config['database'] ?? '',
                    config: array_merge($config, compact('name'))
                );
            });
        });

        $this->app->beforeResolving(MigrateInstallCommand::class, function () {
            $this->app->singleton('migration.repository', function ($app) {
                $migrations = $app['config']['database.migrations'];

                $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

                return new DatabaseMigrationRepository($app['db'], $table);
            });
        });
    }
}

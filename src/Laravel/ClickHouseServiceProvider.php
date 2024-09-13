<?php

namespace SwooleTW\ClickHouse\Laravel;

use Illuminate\Support\ServiceProvider;
use SwooleTW\ClickHouse\Laravel\Eloquent\Model;

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
    }
}

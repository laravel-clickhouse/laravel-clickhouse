<?php

namespace ClickHouse\Laravel\Facades;

use ClickHouse\Laravel\Schema\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void dropSync(string $table)
 * @method static void dropIfExistsSync(string $table)
 *
 * @see Builder
 */
class Schema extends Facade
{
    /**
     * Get a ClickHouse schema builder instance for a connection.
     */
    public static function connection(?string $name = null): Builder
    {
        // @phpstan-ignore-next-line
        $builder = static::$app['db']->connection($name)->getSchemaBuilder();
        assert($builder instanceof Builder);

        return $builder;
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'db.schema';
    }
}

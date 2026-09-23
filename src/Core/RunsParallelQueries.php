<?php

namespace ClickHouse\Core;

use ClickHouse\Core\Contracts\ClickHouseConnection;
use InvalidArgumentException;

/**
 * Parallel query execution shared by every framework bridge. The using
 * class binds its framework via three class constants: QUERY_BUILDER,
 * ELOQUENT_BUILDER and COLLECTION.
 */
trait RunsParallelQueries
{
    /**
     * @param  array<int|string, mixed>  $queries
     * @return array<int|string, mixed>
     */
    public static function get(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        // instanceof against a constant expression requires PHP 8.3; the
        // class-strings are read into locals to stay compatible with 8.2.
        $queryBuilder = static::QUERY_BUILDER;
        $eloquentBuilder = static::ELOQUENT_BUILDER;

        $connections = [];

        foreach ($queries as $query) {
            if (! $query instanceof $queryBuilder && ! $query instanceof $eloquentBuilder) {
                throw new InvalidArgumentException('Query must be an instance of '.$queryBuilder.' or '.$eloquentBuilder.'.');
            }

            $connection = $query->getConnection();

            if (! in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }

        if (count($connections) > 1) {
            throw new InvalidArgumentException('All queries must use the same connection.');
        }

        /** @var ClickHouseConnection $connection */
        $connection = $connections[0];

        $queries = array_map(function ($query) use ($eloquentBuilder) {
            if ($query instanceof $eloquentBuilder) {
                $query = $query->applyScopes();
            }

            return [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'query' => $query,
            ];
        }, $queries);

        $results = [];

        $collection = static::COLLECTION;

        foreach ($connection->selectParallelly($queries) as $key => $result) {
            $query = $queries[$key]['query'];

            $items = $query->applyAfterQueryCallbacks(new $collection($result));

            if (! $query instanceof $eloquentBuilder) {
                $results[$key] = $items;

                continue;
            }

            if (count($models = $query->hydrate($items->all())->all()) > 0) {
                $models = $query->eagerLoadRelations($models);
            }

            $results[$key] = $query->applyAfterQueryCallbacks(
                $query->getModel()->newCollection($models)
            );
        }

        return $results;
    }
}

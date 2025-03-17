<?php

namespace ClickHouse\Laravel;

use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Parallel
{
    /**
     * @param  array<int|string, QueryBuilder|EloquentBuilder<Model>>  $queries
     * @return array<int|string, mixed>
     */
    public static function get(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        foreach ($queries as $query) {
            // @phpstan-ignore-next-line
            if (! $query instanceof QueryBuilder && ! $query instanceof EloquentBuilder) {
                throw new InvalidArgumentException('Query must be an instance of '.QueryBuilder::class.' or '.EloquentBuilder::class.'.');
            }
        }

        $connections = collect($queries)->map(function ($query) {
            return $query->getConnection();
        });

        if ($connections->unique()->count() > 1) {
            throw new InvalidArgumentException('All queries must use the same connection.');
        }

        /** @var Connection $connection */
        $connection = $connections->first();

        $queries = array_map(function ($query) {
            if ($query instanceof EloquentBuilder) {
                $query = $query->applyScopes();
            }

            return [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'query' => $query,
            ];
        }, $queries);

        return collect($connection->selectParallelly($queries))->map(function ($result, $key) use ($queries) {
            $query = $queries[$key]['query'];

            /** @var Collection<int, Model> $items */
            $items = $query->applyAfterQueryCallbacks(collect($result));

            if (! $query instanceof EloquentBuilder) {
                return $items;
            }

            if (count($models = $query->hydrate($items->all())->all()) > 0) {
                $models = $query->eagerLoadRelations($models);
            }

            return $query->applyAfterQueryCallbacks(
                $query->getModel()->newCollection($models)
            );
        })->all();
    }
}

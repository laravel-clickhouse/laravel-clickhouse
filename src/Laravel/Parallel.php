<?php

namespace ClickHouse\Laravel;

use ClickHouse\Core\RunsParallelQueries;
use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;

/**
 * @method static array<int|string, mixed> get(array<int|string, QueryBuilder|EloquentBuilder<Model>> $queries)
 */
class Parallel
{
    use RunsParallelQueries;

    protected const QUERY_BUILDER = QueryBuilder::class;

    protected const ELOQUENT_BUILDER = EloquentBuilder::class;

    protected const COLLECTION = Collection::class;
}

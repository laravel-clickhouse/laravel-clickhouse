<?php

namespace ClickHouse\Hypervel;

use ClickHouse\Core\RunsParallelQueries;
use ClickHouse\Hypervel\Eloquent\Model;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Support\Collection;

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

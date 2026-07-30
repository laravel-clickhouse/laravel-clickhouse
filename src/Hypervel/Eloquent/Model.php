<?php

namespace ClickHouse\Hypervel\Eloquent;

use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use Hypervel\Database\Eloquent\Model as BaseModel;
use Hypervel\Database\Eloquent\Scope;

/**
 * @method static Builder<static> query()
 * @method Builder<static> newQuery()
 * @method Builder<static> newModelQuery()
 * @method Builder<static> newQueryWithoutRelationships()
 * @method Builder<static> newQueryWithoutScopes()
 * @method Builder<static> newQueryWithoutScope(Scope|string $scope)
 * @method Builder<static> newQueryForRestoration(array|int|string $ids)
 * @method Builder<*> newEloquentBuilder(QueryBuilder $query)
 * @method QueryBuilder newBaseQueryBuilder()
 */
abstract class Model extends BaseModel
{
    /** {@inheritDoc} */
    public bool $incrementing = false;

    /**
     * {@inheritDoc}
     *
     * @var class-string<Builder<Model>>
     */
    protected static string $builder = Builder::class;
}

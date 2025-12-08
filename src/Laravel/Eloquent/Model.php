<?php

namespace ClickHouse\Laravel\Eloquent;

use ClickHouse\Laravel\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\Scope;

/**
 * @method static Builder<static> query()
 * @method Builder<static> newQuery()
 * @method Builder<static> newModelQuery()
 * @method Builder<static> newQueryWithoutRelationships()
 * @method Builder<static> newQueryWithoutScopes()
 * @method Builder<static> newQueryWithoutScope(Scope|string $scope)
 * @method Builder<static> newQueryForRestoration(array|int $ids)
 * @method Builder<*> newEloquentBuilder(QueryBuilder $query)
 * @method QueryBuilder newBaseQueryBuilder()
 */
abstract class Model extends BaseModel
{
    /** {@inheritDoc} */
    public $incrementing = false;

    /**
     * {@inheritDoc}
     *
     * @var class-string<Builder<Model>>
     */
    protected static string $builder = Builder::class;
}

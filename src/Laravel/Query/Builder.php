<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Core\Query\BuildsClickHouseQueries;
use ClickHouse\Core\Query\ClickHouseBuilder;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder implements ClickHouseBuilder
{
    use BuildsClickHouseQueries;

    protected const EXPRESSION = Expression::class;

    protected const EXPRESSION_CONTRACT = ExpressionContract::class;
}

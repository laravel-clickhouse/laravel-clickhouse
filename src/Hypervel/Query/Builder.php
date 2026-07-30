<?php

namespace ClickHouse\Hypervel\Query;

use ClickHouse\Core\Query\BuildsClickHouseQueries;
use ClickHouse\Core\Query\ClickHouseBuilder;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Expression;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder implements ClickHouseBuilder
{
    use BuildsClickHouseQueries;

    protected const EXPRESSION = Expression::class;

    protected const EXPRESSION_CONTRACT = ExpressionContract::class;
}

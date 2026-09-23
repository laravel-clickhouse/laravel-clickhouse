<?php

namespace ClickHouse\Hypervel\Query;

use ClickHouse\Core\Query\CompilesClickHouseQueries;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar as BaseGrammar;

class Grammar extends BaseGrammar
{
    use CompilesClickHouseQueries;

    protected const EXPRESSION = Expression::class;
}

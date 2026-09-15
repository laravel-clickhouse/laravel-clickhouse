<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Core\Query\CompilesClickHouseQueries;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;

class Grammar extends BaseGrammar
{
    use CompilesClickHouseQueries;

    protected const EXPRESSION = Expression::class;
}

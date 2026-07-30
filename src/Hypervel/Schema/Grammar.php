<?php

namespace ClickHouse\Hypervel\Schema;

use ClickHouse\Core\Schema\CompilesClickHouseSchema;
use Hypervel\Database\Connection as BaseConnection;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Grammars\Grammar as BaseGrammar;

class Grammar extends BaseGrammar
{
    use CompilesClickHouseSchema;

    protected const EXPRESSION = Expression::class;

    /** {@inheritDoc} */
    protected function resolveConnection(): BaseConnection
    {
        return $this->connection;
    }
}

<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Core\Schema\CompilesClickHouseSchema;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use RuntimeException;

class Grammar extends BaseGrammar
{
    use CompilesClickHouseSchema;

    protected const EXPRESSION = Expression::class;

    /**
     * Resolve the connection from the grammar instance.
     *
     * Laravel 11/12 set it via setConnection(); Laravel 13 sets it through
     * the grammar constructor. In both cases it lives on the base grammar's
     * protected $connection property.
     */
    protected function resolveConnection(): BaseConnection
    {
        // @phpstan-ignore-next-line
        $connection = $this->connection ?? null;

        if (! $connection instanceof BaseConnection) {
            throw new RuntimeException('ClickHouse schema grammar has no connection bound.');
        }

        return $connection;
    }
}

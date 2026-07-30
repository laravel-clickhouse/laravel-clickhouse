<?php

namespace ClickHouse\Hypervel\Schema;

use ClickHouse\Core\Schema\BuildsClickHouseSchema;
use ClickHouse\Hypervel\Connection;
use Closure;
use Hypervel\Database\Schema\Builder as BaseBuilder;

/**
 * @property Connection $connection
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder
{
    use BuildsClickHouseSchema;

    /** {@inheritDoc} */
    protected function createBlueprint(string $table, ?Closure $callback = null): Blueprint
    {
        if (isset($this->resolver)) {
            /** @var Blueprint */
            return call_user_func($this->resolver, $this->connection, $table, $callback);
        }

        return new Blueprint($this->connection, $table, $callback);
    }
}

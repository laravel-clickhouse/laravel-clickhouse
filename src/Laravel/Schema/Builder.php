<?php

namespace ClickHouse\Laravel\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Builder as BaseBuilder;

/**
 * @property Grammar $grammar
 */
class Builder extends BaseBuilder
{
    /**
     * {@inheritDoc}
     */
    public function dropAllTables()
    {
        $tables = array_column($this->getTables(), 'name');

        if (empty($tables)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllTables($tables)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function dropAllViews()
    {
        $views = array_column($this->getViews(), 'name');

        if (empty($views)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllViews($views)
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        $prefix = $this->connection->getConfig('prefix_indexes')
                    ? $this->connection->getConfig('prefix')
                    : '';

        // @phpstan-ignore-next-line
        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $table, $callback, $prefix);
        }

        // @phpstan-ignore-next-line
        return Container::getInstance()->make(Blueprint::class, compact('table', 'callback', 'prefix'));
    }
}

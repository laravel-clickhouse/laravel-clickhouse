<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Laravel\Connection;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Builder as BaseBuilder;

/**
 * @property Connection $connection
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

        foreach ($tables as $table) {
            $this->connection->statement(
                'DROP TABLE IF EXISTS '.$this->grammar->wrapTable($table).' SYNC'
            );
        }
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

        foreach ($views as $view) {
            $this->connection->statement(
                'DROP VIEW IF EXISTS '.$this->grammar->wrapTable($view).' SYNC'
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        $prefix = $this->connection->getConfig('prefix_indexes')
                    ? $this->connection->getConfig('prefix')
                    : '';

        if (($resolver = $this->getBlueprintResolver()) !== null) {
            return $resolver($table, $callback, $prefix);
        }

        return Container::getInstance()->make(Blueprint::class, [
            'connection' => $this->connection,
            'table' => $table,
            'callback' => $callback,
        ]);
    }

    /**
     * Read the base Schema\Builder's blueprint resolver. Laravel 13
     * PHPDoc-types it as a non-nullable Closure but at runtime it
     * starts unset — and is always unset on Laravel 11. The mixed-typed
     * local defeats PHPStan's narrowing of the declared property type.
     */
    private function getBlueprintResolver(): ?Closure
    {
        /** @var mixed $resolver */
        $resolver = isset($this->resolver) ? $this->resolver : null;

        return $resolver instanceof Closure ? $resolver : null;
    }
}

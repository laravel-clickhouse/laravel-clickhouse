<?php

namespace ClickHouse\Core\Schema;

/**
 * ClickHouse-specific blueprint commands shared by every framework bridge.
 * The using class must extend its framework's schema Blueprint, whose
 * addCommand()/addColumn() API this trait relies on.
 */
trait ClickHouseBlueprintMethods
{
    /**
     * Set the PARTITION BY clause for the table.
     *
     * @return mixed
     */
    public function partitionBy(string $expression)
    {
        return $this->addCommand('partitionBy', compact('expression'));
    }

    /**
     * Set the ORDER BY clause for the table.
     *
     * @param  array<string>|string  ...$columns
     * @return mixed
     */
    public function orderBy(array|string ...$columns)
    {
        $columns = is_array($columns[0]) ? $columns[0] : $columns;

        return $this->addCommand('orderBy', compact('columns'));
    }

    /**
     * Create a new Array column on the table.
     *
     * @return mixed
     */
    public function array(string $column, string $type)
    {
        return $this->addColumn('array', $column, ['innerType' => $type]);
    }
}

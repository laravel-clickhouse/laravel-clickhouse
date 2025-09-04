<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Support\Fluent;

class Blueprint extends BaseBlueprint
{
    /**
     * Set the PARTITION BY clause for the table.
     *
     * @return Fluent<string, mixed>
     */
    public function partitionBy(string $expression): Fluent
    {
        return $this->addCommand('partitionBy', compact('expression'));
    }

    /**
     * Set the ORDER BY clause for the table.
     *
     * @param  array<string>|string  ...$columns
     * @return Fluent<string, mixed>
     */
    public function orderBy(array|string ...$columns): Fluent
    {
        $columns = is_array($columns[0]) ? $columns[0] : $columns;

        return $this->addCommand('orderBy', compact('columns'));
    }

    /**
     * Create a new LowCardinality column on the table.
     *
     * @return Fluent<string, mixed>
     */
    public function lowCardinality(string $column, string $type): Fluent
    {
        return $this->addColumn('lowCardinality', $column, ['innerType' => $type]);
    }

    /**
     * Create a new Array column on the table.
     *
     * @return Fluent<string, mixed>
     */
    public function array(string $column, string $type): Fluent
    {
        return $this->addColumn('array', $column, ['innerType' => $type]);
    }
}

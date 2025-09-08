<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Support\Fluent;

/**
 * @method ColumnDefinition char(string $column, int|null $length = null)
 * @method ColumnDefinition string(string $column, int|null $length = null)
 * @method ColumnDefinition tinyText(string $column)
 * @method ColumnDefinition text(string $column)
 * @method ColumnDefinition mediumText(string $column)
 * @method ColumnDefinition longText(string $column)
 * @method IndexDefinition primary(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition unique(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition index(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition fullText(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition spatialIndex(string|string[] $columns, string|null $name = null)
 * @method IndexDefinition rawIndex(string $expression, string $name)
 */
class Blueprint extends BaseBlueprint
{
    /**
     * Set the PARTITION BY clause for the table.
     *
     * @return Fluent<string, string>
     */
    public function partitionBy(string $expression): Fluent
    {
        return $this->addCommand('partitionBy', compact('expression'));
    }

    /**
     * Set the ORDER BY clause for the table.
     *
     * @param  array<string>|string  ...$columns
     * @return Fluent<string, string>
     */
    public function orderBy(array|string ...$columns): Fluent
    {
        $columns = is_array($columns[0]) ? $columns[0] : $columns;

        return $this->addCommand('orderBy', compact('columns'));
    }

    /**
     * Create a new Array column on the table.
     *
     * @return Fluent<string, string>
     */
    public function array(string $column, string $type): Fluent
    {
        return $this->addColumn('array', $column, ['innerType' => $type]);
    }
}

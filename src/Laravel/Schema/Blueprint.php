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

    /**
     * {@inheritDoc}
     *
     * @param  string|string[]  $columns
     * @return IndexDefinition
     */
    public function primary($columns, $name = null, $algorithm = null)
    {
        // @phpstan-ignore-next-line
        return parent::primary($columns, $name, $algorithm);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|string[]  $columns
     * @return IndexDefinition
     */
    public function unique($columns, $name = null, $algorithm = null)
    {
        // @phpstan-ignore-next-line
        return parent::unique($columns, $name, $algorithm);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|string[]  $columns
     * @return IndexDefinition
     */
    public function index($columns, $name = null, $algorithm = null)
    {
        // @phpstan-ignore-next-line
        return parent::index($columns, $name, $algorithm);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|string[]  $columns
     * @return IndexDefinition
     */
    public function fullText($columns, $name = null, $algorithm = null)
    {
        // @phpstan-ignore-next-line
        return parent::fullText($columns, $name, $algorithm);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|string[]  $columns
     * @return IndexDefinition
     */
    public function spatialIndex($columns, $name = null)
    {
        // @phpstan-ignore-next-line
        return parent::spatialIndex($columns, $name);
    }

    /**
     * {@inheritDoc}
     *
     * @return IndexDefinition
     */
    public function rawIndex($expression, $name)
    {
        // @phpstan-ignore-next-line
        return parent::rawIndex($expression, $name);
    }
}

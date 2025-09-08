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
 * @method ColumnDefinition date(string $column)
 * @method ColumnDefinition dateTime(string $column, int|null $precision = null)
 * @method ColumnDefinition dateTimeTz(string $column, int|null $precision = null)
 * @method ColumnDefinition time(string $column, int|null $precision = null)
 * @method ColumnDefinition timeTz(string $column, int|null $precision = null)
 * @method ColumnDefinition timestamp(string $column, int|null $precision = null)
 * @method ColumnDefinition timestampTz(string $column, int|null $precision = null)
 * @method ColumnDefinition softDeletes(string $column = 'deleted_at', int|null $precision = null)
 * @method ColumnDefinition softDeletesTz(string $column = 'deleted_at', int|null $precision = null)
 * @method ColumnDefinition softDeletesDatetime(string $column = 'deleted_at', int|null $precision = null)
 * @method ColumnDefinition year(string $column)
 * @method ColumnDefinition integer(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method ColumnDefinition tinyInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method ColumnDefinition smallInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method ColumnDefinition mediumInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method ColumnDefinition bigInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method ColumnDefinition unsignedInteger(string $column, bool $autoIncrement = false)
 * @method ColumnDefinition unsignedTinyInteger(string $column, bool $autoIncrement = false)
 * @method ColumnDefinition unsignedSmallInteger(string $column, bool $autoIncrement = false)
 * @method ColumnDefinition unsignedMediumInteger(string $column, bool $autoIncrement = false)
 * @method ColumnDefinition unsignedBigInteger(string $column, bool $autoIncrement = false)
 * @method ColumnDefinition float(string $column, int|null $total = null, int|null $places = null)
 * @method ColumnDefinition double(string $column, int|null $total = null, int|null $places = null)
 * @method IndexDefinition primary(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition unique(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition index(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition fullText(string|string[] $columns, string|null $name = null, string|null $algorithm = null)
 * @method IndexDefinition spatialIndex(string|string[] $columns, string|null $name = null)
 * @method IndexDefinition rawIndex(string $expression, string $name)
 * @method CommandDefinition drop()
 * @method CommandDefinition dropIfExists()
 */
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
     * Create a new Array column on the table.
     *
     * @return Fluent<string, mixed>
     */
    public function array(string $column, string $type): Fluent
    {
        return $this->addColumn('array', $column, ['innerType' => $type]);
    }
}

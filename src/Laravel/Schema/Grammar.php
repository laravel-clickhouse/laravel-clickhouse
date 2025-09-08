<?php

namespace ClickHouse\Laravel\Schema;

use BackedEnum;
use ClickHouse\Laravel\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Fluent;
use RuntimeException;

class Grammar extends BaseGrammar
{
    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected $modifiers = ['Increment', 'First', 'After', 'VirtualAs', 'StoredAs', 'Invisible', 'Default', 'Comment'];

    /**
     * The possible type decorators.
     *
     * @var string[]
     */
    protected $decorators = ['Unsigned', 'Nullable', 'LowCardinality'];

    /**
     * {@inheritDoc}
     */
    public function compileCreateDatabase($name, $connection): string
    {
        return sprintf('CREATE DATABASE %s', $this->wrapValue($name));
    }

    /**
     * Compile the query to determine the tables.
     */
    public function compileTables(): string
    {
        return "SELECT name AS name, total_bytes AS size, comment AS comment, engine AS engine, '' AS collation FROM system.tables WHERE database = currentDatabase() AND engine NOT LIKE '%View'";
    }

    /**
     * Compile the query to determine the views.
     */
    public function compileViews(): string
    {
        return "SELECT name AS name, total_bytes AS size, comment AS comment, engine AS engine, '' AS collation FROM system.tables WHERE database = currentDatabase() AND engine LIKE '%View'";
    }

    /**
     * Compile a create table command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, Connection $connection): string
    {
        $sql = $this->compileCreateTable(
            $blueprint, $command, $connection
        );

        return $this->compileCreateEngine($sql, $connection, $blueprint);
    }

    /**
     * Compile an add column command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('ALTER TABLE %s ADD COLUMN %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );
    }

    /**
     * Compile a primary key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding primary keys.');
    }

    /**
     * Compile a unique key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding unique keys.');
    }

    /**
     * Compile a plain index key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileKey($blueprint, $command, 'INDEX');
    }

    /**
     * Compile a unique key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding fulltext indexes.');
    }

    /**
     * Compile a unique key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding spatial indexes.');
    }

    /**
     * Compile a foreign key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support foreign keys.');
    }

    /**
     * Compile a drop table command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        $sql = 'DROP TABLE '.$this->wrapTable($blueprint);

        // @phpstan-ignore-next-line
        if ($command->sync) {
            $sql .= ' SYNC';
        }

        return $sql;
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        $sql = 'DROP TABLE IF EXISTS '.$this->wrapTable($blueprint);

        // @phpstan-ignore-next-line
        if ($command->sync) {
            $sql .= ' SYNC';
        }

        return $sql;
    }

    /**
     * Compile a drop column command.
     *
     * @param  Fluent<string, string>  $command
     * @return string[]
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        return array_map(function ($column) use ($blueprint) {
            return sprintf('ALTER TABLE %s DROP COLUMN %s',
                $this->wrapTable($blueprint),
                $this->wrap($column)
            );
        }, $command->columns);
    }

    /**
     * Compile a drop primary key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping primary keys.');
    }

    /**
     * Compile a drop unique key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping unique keys.');
    }

    /**
     * Compile a drop index command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('ALTER TABLE %s DROP INDEX %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->index)
        );
    }

    /**
     * Compile a drop spatial index command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping spatial indexes.');
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping foreign keys.');
    }

    /**
     * Compile a rename table command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf('RENAME TABLE %s TO %s',
            $this->wrapTable($blueprint),
            $this->wrapTable($command->to)
        );
    }

    /**
     * Compile a rename index command.
     *
     * @param  Fluent<string, string>  $command
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support renaming indexes, please drop and re-create the index instead.');
    }

    /**
     * Create the main create table clause.
     *
     * @param  Fluent<string, string>  $command
     */
    protected function compileCreateTable(Blueprint $blueprint, Fluent $command, Connection $connection): string
    {
        $tableStructure = [];
        $tableIndexes = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            $tableStructure[] = $this->getColumn($blueprint, $column);

            $attributes = $column->getAttributes();

            if (array_key_exists('primary', $attributes)) {
                $tableIndexes[] = sprintf('PRIMARY KEY (%s)', $column->name);
            }
        }

        if ($primaryKey = $this->getCommandByName($blueprint, 'primary')) {
            $primaryKey->shouldBeSkipped = true;
        }

        return sprintf('%s TABLE %s (%s)',
            $blueprint->temporary ? 'CREATE TEMPORARY' : 'CREATE',
            $this->wrapTable($blueprint),
            implode(', ', [...$tableStructure, ...$tableIndexes])
        );
    }

    /**
     * Append the engine specifications to a command.
     */
    protected function compileCreateEngine(string $sql, Connection $connection, Blueprint $blueprint): string
    {
        // @phpstan-ignore-next-line
        if (isset($blueprint->engine)) {
            $sql = "{$sql} ENGINE = {$blueprint->engine}";
        } elseif (! is_null($engine = $connection->getConfig('engine'))) {
            // @phpstan-ignore-next-line
            $sql = "{$sql} ENGINE = {$engine}";
        } else {
            $sql = "{$sql} ENGINE = Memory";
        }

        if ($partitionBy = $this->getCommandByName($blueprint, 'partitionBy')) {
            $sql .= " PARTITION BY {$partitionBy->expression}";
        }

        if ($orderBy = $this->getCommandByName($blueprint, 'orderBy')) {
            $columns = implode(', ', $orderBy->columns);
            $sql .= " ORDER BY ({$columns})";
        }

        return $sql;
    }

    /**
     * Compile an index creation command.
     *
     * @param  Fluent<string, string>  $command
     */
    protected function compileKey(Blueprint $blueprint, Fluent $command, string $type): string
    {
        if (count($command->columns) === 1 && $command->columns[0] instanceof Expression) {
            return sprintf('ALTER TABLE %s ADD %s %s %s',
                $this->wrapTable($blueprint),
                $type,
                $this->wrap($command->index),
                $this->wrap($command->columns[0])
            );
        }

        if (! $command->algorithm) {
            throw new RuntimeException('ClickHouse requires an algorithm for index creation.');
        }

        if (count($command->columns) > 1) {
            throw new RuntimeException('ClickHouse does not support composite indexes.');
        }

        return sprintf('ALTER TABLE %s ADD %s %s %s TYPE %s GRANULARITY %d',
            $this->wrapTable($blueprint),
            $type,
            $this->wrap($command->index),
            $this->columnize($command->columns),
            $command->algorithm,
            $command->granularity ?: 1
        );
    }

    /**
     * {@inheritDoc}
     */
    public function compileDropDatabaseIfExists($name): string
    {
        return sprintf(
            'DROP DATABASE IF EXISTS %s',
            $this->wrapValue($name)
        );
    }

    /**
     * Compile the SQL needed to drop all tables.
     *
     * @param  string[]  $tables
     */
    public function compileDropAllTables(array $tables): string
    {
        return 'DROP TABLE '.implode(', ', $this->wrapArray($tables));
    }

    /**
     * Compile the SQL needed to drop all views.
     *
     * @param  string[]  $views
     */
    public function compileDropAllViews(array $views): string
    {
        return 'DROP VIEW '.implode(', ', $this->wrapArray($views));
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): void
    {
        if ($column->autoIncrement) {
            throw new RuntimeException('ClickHouse does not support auto increment.');
        }
    }

    /**
     * Get the SQL for a "first" column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyFirst(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->first)) {
            return ' FIRST';
        }

        return null;
    }

    /**
     * Get the SQL for an "after" column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyAfter(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->after)) {
            return ' AFTER '.$this->wrap($column->after);
        }

        return null;
    }

    /**
     * Get the SQL for a generated virtual column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($virtualAs = $column->virtualAsJson)) {
            if ($this->isJsonSelector($virtualAs)) {
                $virtualAs = $this->wrapJsonSelector($virtualAs);
            }

            return " ALIAS {$virtualAs}";
        }

        if (! is_null($virtualAs = $column->virtualAs)) {
            return " ALIAS {$this->getValue($virtualAs)}";
        }

        return null;
    }

    /**
     * Get the SQL for a generated stored column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($storedAs = $column->storedAsJson)) {
            if ($this->isJsonSelector($storedAs)) {
                $storedAs = $this->wrapJsonSelector($storedAs);
            }

            return " MATERIALIZED {$storedAs}";
        }

        if (! is_null($storedAs = $column->storedAs)) {
            return " MATERIALIZED {$this->getValue($storedAs)}";
        }

        return null;
    }

    /**
     * Get the SQL for an invisible column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyInvisible(Blueprint $blueprint, Fluent $column): void
    {
        if (! is_null($column->invisible)) {
            throw new RuntimeException('ClickHouse does not support invisible columns.');
        }
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (! is_null($column->default)) {
            return ' DEFAULT '.$this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for a comment column modifier.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function modifyComment(Blueprint $blueprint, Fluent $column): void
    {
        if (! is_null($column->comment)) {
            throw new RuntimeException('ClickHouse does not support comments on columns.');
        }
    }

    /**
     * Get the SQL for an unsigned column decorator.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function decorateUnsigned(Blueprint $blueprint, Fluent $column, string $type): string
    {
        if ($column->unsigned) {
            return "U{$type}";
        }

        return $type;
    }

    /**
     * Get the SQL for a nullable column decorator.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function decorateNullable(Blueprint $blueprint, Fluent $column, string $type): string
    {
        if ($column->nullable) {
            return "Nullable($type)";
        }

        return $type;
    }

    /**
     * Get the SQL for a low cardinality column decorator.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function decorateLowCardinality(Blueprint $blueprint, Fluent $column, string $type): string
    {
        if ($column->lowCardinality) {
            return "LowCardinality($type)";
        }

        return $type;
    }

    /**
     * Create the column definition for a char type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeChar(Fluent $column): string
    {
        return "FixedString({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeString(Fluent $column): string
    {
        return "FixedString({$column->length})";
    }

    /**
     * Create the column definition for a tiny text type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeMediumText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeLongText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'Int64';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'Int32';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'Int16';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'Int8';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeFloat(Fluent $column): string
    {
        return 'Float32';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'Float64';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeDecimal(Fluent $column): string
    {
        return "Decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'Bool';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeEnum(Fluent $column): string
    {
        return sprintf('Enum(%s)', $this->quoteString($column->allowed));
    }

    /**
     * Create the column definition for a set enumeration type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeSet(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse does not support set columns.');
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeJson(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse driver does not support json columns yet.');
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeJsonb(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse driver does not support json columns yet.');
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeDate(Fluent $column): string
    {
        return 'Date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeDateTime(Fluent $column): string
    {
        $current = $column->precision ? "now64($column->precision)" : 'now()';

        if ($column->useCurrent) {
            $column->default(new Expression($current));
        }

        if ($column->useCurrentOnUpdate) {
            throw new RuntimeException('ClickHouse does not support on update current timestamp.');
        }

        return $column->precision ? "DateTime64($column->precision)" : 'DateTime';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeDateTimeTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTime(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse does not support time columns, please use datetime columns instead.');
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTimeTz(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse does not support time columns, please use datetime columns instead.');
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTimestamp(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a year type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeYear(Fluent $column): void
    {
        throw new RuntimeException('ClickHouse does not support year columns.');
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeBinary(Fluent $column): string
    {
        if ($column->length) {
            return $column->fixed ? "binary({$column->length})" : "varbinary({$column->length})";
        }

        return 'String';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'FixedString(36)';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'FixedString(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param  Fluent<string, string>  $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'FixedString(17)';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     *
     * @param  Fluent<string, string>  $column
     * @return string
     */
    protected function typeGeometry(Fluent $column)
    {
        $subtype = $column->subtype ? strtolower($column->subtype) : null;

        if (! in_array($subtype, ['point', 'linestring', 'polygon', 'geometrycollection', 'multipoint', 'multilinestring', 'multipolygon'])) {
            $subtype = null;
        }

        return sprintf('%s%s',
            $subtype ?? 'geometry',
            match (true) {
                (bool) $column->srid => ' srid '.$column->srid,
                default => '',
            }
        );
    }

    /**
     * Create the column definition for a spatial Geography type.
     *
     * @param  Fluent<string, string>  $column
     * @return string
     */
    protected function typeGeography(Fluent $column)
    {
        return $this->typeGeometry($column);
    }

    /**
     * Create the column definition for a generated, computed column type.
     *
     * @param  Fluent<string, string>  $column
     * @return void
     */
    protected function typeComputed(Fluent $column)
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
    }

    /**
     * Create the column definition for a vector type.
     *
     * @param  Fluent<string, string>  $column
     * @return string
     */
    protected function typeVector(Fluent $column)
    {
        return isset($column->dimensions) && $column->dimensions !== ''
            ? "vector({$column->dimensions})"
            : 'vector';
    }

    /**
     * Create the column definition for an Array type.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function typeArray(Fluent $column): string
    {
        return "Array({$column->innerType})";
    }

    /**
     * {@inheritDoc}
     */
    protected function wrapValue($value)
    {
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    // protected function wrapJsonSelector($value)
    // {
    //     [$field, $path] = $this->wrapJsonFieldAndPath($value);
    //
    //     return 'json_unquote(json_extract('.$field.$path.'))';
    // }

    /**
     * {@inheritDoc}
     */
    protected function getColumn(Blueprint $blueprint, $column)
    {
        $type = $this->addDecorators($blueprint, $column, $this->getType($column));
        $sql = $this->wrap($column).' '.$type;

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Add the column decorators to the definition.
     *
     * @param  Fluent<string, string>  $column
     */
    protected function addDecorators(Blueprint $blueprint, Fluent $column, string $type): string
    {
        foreach ($this->decorators as $decorator) {
            if (method_exists($this, $method = "decorate{$decorator}")) {
                $type = $this->{$method}($blueprint, $column, $type);
            }
        }

        return $type;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultValue($value)
    {
        if ($value instanceof Expression) {
            // @phpstan-ignore-next-line
            return $this->getValue($value);
        }

        if ($value instanceof BackedEnum) {
            return "'{$value->value}'";
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            // @phpstan-ignore-next-line
            default => "'".(string) $value."'",
        };
    }
}

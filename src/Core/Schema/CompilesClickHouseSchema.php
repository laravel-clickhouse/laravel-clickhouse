<?php

namespace ClickHouse\Core\Schema;

use BackedEnum;
use RuntimeException;

/**
 * ClickHouse-specific DDL compilation shared by every framework bridge.
 * The using class must extend its framework's schema grammar, whose
 * inherited API (wrap, wrapTable, quoteString, getType, addModifiers,
 * getCommandByName, ...) this trait relies on. Blueprint and Fluent
 * parameters are intentionally untyped so both frameworks' classes fit.
 */
trait CompilesClickHouseSchema
{
    /**
     * Create a new grammar instance, registering the ClickHouse column
     * modifiers. The parent property cannot be redeclared here — its type
     * differs between frameworks (untyped vs array) and a trait property
     * must match exactly — so the modifiers are set at construction.
     * Laravel 11's grammar has no constructor, hence the guard.
     *
     * @param  mixed  $connection
     */
    public function __construct($connection = null)
    {
        if (method_exists(parent::class, '__construct')) {
            parent::__construct($connection);
        }

        $this->modifiers = ['Increment', 'First', 'After', 'VirtualAs', 'StoredAs', 'Invisible', 'Default', 'Comment'];
    }

    /**
     * The possible type decorators.
     *
     * @var string[]
     */
    protected $decorators = ['Unsigned', 'Nullable', 'LowCardinality'];

    /**
     * {@inheritDoc}
     *
     * @param  string  $name
     * @param  mixed  $connection
     */
    public function compileCreateDatabase($name, $connection = null): string
    {
        return sprintf('CREATE DATABASE %s', $this->wrapValue($name));
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileTables($schema = null): string
    {
        return "SELECT name AS name, total_bytes AS size, comment AS comment, engine AS engine, '' AS collation FROM system.tables WHERE database = currentDatabase() AND engine NOT LIKE '%View'";
    }

    /**
     * Compile the query to determine the views.
     *
     * @param  string|string[]|null  $schema
     */
    public function compileViews($schema = null): string
    {
        return "SELECT name AS name, total_bytes AS size, comment AS comment, engine AS engine, '' AS collation FROM system.tables WHERE database = currentDatabase() AND engine LIKE '%View'";
    }

    /**
     * Compile the query to determine the columns.
     *
     * @param  string|null  $schema
     * @param  string  $table
     */
    public function compileColumns($schema, $table): string
    {
        return sprintf(
            "SELECT name AS name, type AS type_name, type AS type, '' AS collation, "
            ."position(type, 'Nullable(') > 0 AS nullable, "
            .'default_expression AS default, comment AS comment '
            .'FROM system.columns '
            .'WHERE database = %s AND table = %s '
            .'ORDER BY position ASC',
            $schema ? $this->quoteString($schema) : 'currentDatabase()',
            $this->quoteString($table)
        );
    }

    /**
     * Compile a create table command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     * @param  mixed  $connection
     */
    public function compileCreate($blueprint, $command, $connection = null): string
    {
        $connection ??= $this->resolveConnection();

        $sql = $this->compileCreateTable(
            $blueprint, $command, $connection
        );

        return $this->compileCreateEngine($sql, $connection, $blueprint);
    }

    /**
     * Compile an add column command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileAdd($blueprint, $command): string
    {
        return sprintf('ALTER TABLE %s ADD COLUMN %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );
    }

    /**
     * Compile a change column command.
     *
     * ClickHouse keeps unspecified column properties on MODIFY COLUMN, while
     * Laravel expects modifiers omitted from change() to be dropped. To match
     * Laravel semantics, the existing default kind (DEFAULT / MATERIALIZED /
     * ALIAS) is removed first when the new definition does not specify one.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     * @param  mixed  $connection
     * @return array<int, string>
     */
    public function compileChange($blueprint, $command, $connection = null): array
    {
        $statements = [];

        $defaultKindToRemove = $this->defaultKindToRemove($blueprint, $command->column);

        if (! is_null($defaultKindToRemove)) {
            $statements[] = sprintf('ALTER TABLE %s MODIFY COLUMN %s REMOVE %s',
                $this->wrapTable($blueprint),
                $this->wrap($command->column),
                $defaultKindToRemove
            );
        }

        $statements[] = sprintf('ALTER TABLE %s MODIFY COLUMN %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column)
        );

        return $statements;
    }

    /**
     * Compile a primary key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compilePrimary($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding primary keys.');
    }

    /**
     * Compile a unique key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileUnique($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding unique keys.');
    }

    /**
     * Compile a plain index key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileIndex($blueprint, $command): string
    {
        return $this->compileKey($blueprint, $command, 'INDEX');
    }

    /**
     * Compile a fulltext index key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileFulltext($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding fulltext indexes.');
    }

    /**
     * Compile a spatial index key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileSpatialIndex($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support adding spatial indexes.');
    }

    /**
     * Compile a foreign key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileForeign($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support foreign keys.');
    }

    /**
     * Compile a drop table command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDrop($blueprint, $command): string
    {
        $sql = 'DROP TABLE '.$this->wrapTable($blueprint);

        if ($command->sync) {
            $sql .= ' SYNC';
        }

        return $sql;
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropIfExists($blueprint, $command): string
    {
        $sql = 'DROP TABLE IF EXISTS '.$this->wrapTable($blueprint);

        if ($command->sync) {
            $sql .= ' SYNC';
        }

        return $sql;
    }

    /**
     * Compile a drop column command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     * @return string[]
     */
    public function compileDropColumn($blueprint, $command): array
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
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropPrimary($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping primary keys.');
    }

    /**
     * Compile a drop unique key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropUnique($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping unique keys.');
    }

    /**
     * Compile a drop index command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropIndex($blueprint, $command): string
    {
        return sprintf('ALTER TABLE %s DROP INDEX %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->index)
        );
    }

    /**
     * Compile a drop spatial index command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropSpatialIndex($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping spatial indexes.');
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileDropForeign($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support dropping foreign keys.');
    }

    /**
     * Compile a rename table command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileRename($blueprint, $command): string
    {
        return sprintf('RENAME TABLE %s TO %s',
            $this->wrapTable($blueprint),
            $this->wrapTable($command->to)
        );
    }

    /**
     * Compile a rename column command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     * @param  mixed  $connection
     */
    public function compileRenameColumn($blueprint, $command, $connection = null): string
    {
        return sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile a rename index command.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    public function compileRenameIndex($blueprint, $command): never
    {
        throw new RuntimeException('ClickHouse driver does not support renaming indexes, please drop and re-create the index instead.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $name
     */
    public function compileDropDatabaseIfExists($name): string
    {
        return sprintf(
            'DROP DATABASE IF EXISTS %s',
            $this->wrapValue($name)
        );
    }

    /**
     * Create a framework-specific raw SQL expression.
     */
    protected function newExpression(string $value): mixed
    {
        $expression = static::EXPRESSION;

        return new $expression($value);
    }

    /**
     * Resolve the connection bound to the grammar.
     */
    abstract protected function resolveConnection(): mixed;

    /**
     * Determine the existing default kind to remove before changing a column.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function defaultKindToRemove($blueprint, $column): ?string
    {
        if ($this->specifiesDefaultKind($column)) {
            return null;
        }

        $existingDefaultKind = $this->fetchExistingDefaultKind($blueprint, $column);

        return in_array($existingDefaultKind, ['DEFAULT', 'MATERIALIZED', 'ALIAS'], true)
            ? $existingDefaultKind
            : null;
    }

    /**
     * Determine whether the new column definition specifies a default kind.
     *
     * @param  mixed  $column
     */
    protected function specifiesDefaultKind($column): bool
    {
        return ! is_null($column->default)
            || ! is_null($column->storedAs)
            || ! is_null($column->storedAsJson)
            || ! is_null($column->virtualAs)
            || ! is_null($column->virtualAsJson);
    }

    /**
     * Fetch the existing default kind of a column from system.columns.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function fetchExistingDefaultKind($blueprint, $column): ?string
    {
        $connection = $this->resolveConnection();

        $row = $connection->selectOne(sprintf(
            'SELECT default_kind FROM system.columns WHERE database = currentDatabase() AND table = %s AND name = %s',
            $this->quoteString($connection->getTablePrefix().$blueprint->getTable()),
            $this->quoteString((string) $column->name)
        ));

        if (is_null($row)) {
            return null;
        }

        $defaultKind = ((array) $row)['default_kind'] ?? null;

        return is_string($defaultKind) ? $defaultKind : null;
    }

    /**
     * Create the main create table clause.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $command
     * @param  mixed  $connection
     */
    protected function compileCreateTable($blueprint, $command, $connection): string
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
     *
     * @param  mixed  $connection
     * @param  mixed  $blueprint
     */
    protected function compileCreateEngine(string $sql, $connection, $blueprint): string
    {
        if (isset($blueprint->engine)) {
            $sql = "{$sql} ENGINE = {$blueprint->engine}";
        } elseif (! is_null($engine = $connection->getConfig('engine'))) {
            $sql = "{$sql} ENGINE = {$engine}";
        } else {
            $sql = "{$sql} ENGINE = MergeTree()";
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
     * @param  mixed  $blueprint
     * @param  mixed  $command
     */
    protected function compileKey($blueprint, $command, string $type): string
    {
        if (count($command->columns) === 1 && $this->isExpression($command->columns[0])) {
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
     * Get the SQL for an auto-increment column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyIncrement($blueprint, $column): void
    {
        if ($column->autoIncrement) {
            throw new RuntimeException('ClickHouse does not support auto increment.');
        }
    }

    /**
     * Get the SQL for a "first" column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyFirst($blueprint, $column): ?string
    {
        if (! is_null($column->first)) {
            return ' FIRST';
        }

        return null;
    }

    /**
     * Get the SQL for an "after" column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyAfter($blueprint, $column): ?string
    {
        if (! is_null($column->after)) {
            return ' AFTER '.$this->wrap($column->after);
        }

        return null;
    }

    /**
     * Get the SQL for a generated virtual column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyVirtualAs($blueprint, $column): ?string
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
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyStoredAs($blueprint, $column): ?string
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
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyInvisible($blueprint, $column): void
    {
        if (! is_null($column->invisible)) {
            throw new RuntimeException('ClickHouse does not support invisible columns.');
        }
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyDefault($blueprint, $column): ?string
    {
        if (! is_null($column->default)) {
            return ' DEFAULT '.$this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for a comment column modifier.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function modifyComment($blueprint, $column): void
    {
        if (! is_null($column->comment)) {
            throw new RuntimeException('ClickHouse does not support comments on columns.');
        }
    }

    /**
     * Get the SQL for an unsigned column decorator.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function decorateUnsigned($blueprint, $column, string $type): string
    {
        if ($column->unsigned) {
            return "U{$type}";
        }

        return $type;
    }

    /**
     * Get the SQL for a nullable column decorator.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function decorateNullable($blueprint, $column, string $type): string
    {
        if ($column->nullable) {
            return "Nullable($type)";
        }

        return $type;
    }

    /**
     * Get the SQL for a low cardinality column decorator.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function decorateLowCardinality($blueprint, $column, string $type): string
    {
        if ($column->lowCardinality) {
            return "LowCardinality($type)";
        }

        return $type;
    }

    /**
     * Create the column definition for a char type.
     *
     * @param  mixed  $column
     */
    protected function typeChar($column): string
    {
        return "FixedString({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  mixed  $column
     */
    protected function typeString($column): string
    {
        return "FixedString({$column->length})";
    }

    /**
     * Create the column definition for a tiny text type.
     *
     * @param  mixed  $column
     */
    protected function typeTinyText($column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  mixed  $column
     */
    protected function typeText($column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  mixed  $column
     */
    protected function typeMediumText($column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  mixed  $column
     */
    protected function typeLongText($column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  mixed  $column
     */
    protected function typeBigInteger($column): string
    {
        return 'Int64';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  mixed  $column
     */
    protected function typeInteger($column): string
    {
        return 'Int32';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  mixed  $column
     */
    protected function typeMediumInteger($column): string
    {
        return $this->typeInteger($column);
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  mixed  $column
     */
    protected function typeSmallInteger($column): string
    {
        return 'Int16';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  mixed  $column
     */
    protected function typeTinyInteger($column): string
    {
        return 'Int8';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  mixed  $column
     */
    protected function typeFloat($column): string
    {
        return 'Float32';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  mixed  $column
     */
    protected function typeDouble($column): string
    {
        return 'Float64';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  mixed  $column
     */
    protected function typeDecimal($column): string
    {
        return "Decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  mixed  $column
     */
    protected function typeBoolean($column): string
    {
        return 'Bool';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  mixed  $column
     */
    protected function typeEnum($column): string
    {
        return sprintf('Enum(%s)', $this->quoteString($column->allowed));
    }

    /**
     * Create the column definition for a set enumeration type.
     *
     * @param  mixed  $column
     */
    protected function typeSet($column): void
    {
        throw new RuntimeException('ClickHouse does not support set columns.');
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  mixed  $column
     */
    protected function typeJson($column): void
    {
        throw new RuntimeException('ClickHouse driver does not support json columns yet.');
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  mixed  $column
     */
    protected function typeJsonb($column): void
    {
        throw new RuntimeException('ClickHouse driver does not support json columns yet.');
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  mixed  $column
     */
    protected function typeDate($column): string
    {
        return 'Date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  mixed  $column
     */
    protected function typeDateTime($column): string
    {
        $current = $column->precision ? "now64($column->precision)" : 'now()';

        if ($column->useCurrent) {
            $column->default($this->newExpression($current));
        }

        if ($column->useCurrentOnUpdate) {
            throw new RuntimeException('ClickHouse does not support on update current timestamp.');
        }

        return $column->precision ? "DateTime64($column->precision)" : 'DateTime';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * @param  mixed  $column
     */
    protected function typeDateTimeTz($column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  mixed  $column
     */
    protected function typeTime($column): void
    {
        throw new RuntimeException('ClickHouse does not support time columns, please use datetime columns instead.');
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @param  mixed  $column
     */
    protected function typeTimeTz($column): void
    {
        throw new RuntimeException('ClickHouse does not support time columns, please use datetime columns instead.');
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  mixed  $column
     */
    protected function typeTimestamp($column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @param  mixed  $column
     */
    protected function typeTimestampTz($column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a year type.
     *
     * @param  mixed  $column
     */
    protected function typeYear($column): void
    {
        throw new RuntimeException('ClickHouse does not support year columns.');
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  mixed  $column
     */
    protected function typeBinary($column): string
    {
        if ($column->length) {
            return $column->fixed ? "binary({$column->length})" : "varbinary({$column->length})";
        }

        return 'String';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  mixed  $column
     */
    protected function typeUuid($column): string
    {
        return 'UUID';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @param  mixed  $column
     */
    protected function typeIpAddress($column): string
    {
        return 'FixedString(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param  mixed  $column
     * @return string
     */
    protected function typeMacAddress($column)
    {
        return 'FixedString(17)';
    }

    /**
     * Create the column definition for a spatial Geometry type.
     *
     * @param  mixed  $column
     * @return string
     */
    protected function typeGeometry($column)
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
     * @param  mixed  $column
     * @return string
     */
    protected function typeGeography($column)
    {
        return $this->typeGeometry($column);
    }

    /**
     * Create the column definition for a generated, computed column type.
     *
     * @param  mixed  $column
     */
    protected function typeComputed($column): void
    {
        throw new RuntimeException('This database driver requires a type, see the virtualAs / storedAs modifiers.');
    }

    /**
     * Create the column definition for a vector type.
     *
     * @param  mixed  $column
     */
    protected function typeVector($column): string
    {
        return isset($column->dimensions) && $column->dimensions !== ''
            ? "vector({$column->dimensions})"
            : 'vector';
    }

    /**
     * Create the column definition for an Array type.
     *
     * @param  mixed  $column
     */
    protected function typeArray($column): string
    {
        return "Array({$column->innerType})";
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $value
     */
    protected function wrapValue($value): string
    {
        return $value;
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function getColumn($blueprint, $column): string
    {
        $type = $this->addDecorators($blueprint, $column, $this->getType($column));
        $sql = $this->wrap($column).' '.$type;

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Add the column decorators to the definition.
     *
     * @param  mixed  $blueprint
     * @param  mixed  $column
     */
    protected function addDecorators($blueprint, $column, string $type): string
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
     *
     * @param  mixed  $value
     */
    protected function getDefaultValue($value): string|int|float
    {
        if ($this->isExpression($value)) {
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

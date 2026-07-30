<?php

namespace ClickHouse\Core\Query;

use Carbon\Carbon;
use ClickHouse\Enums\Format;
use LogicException;

/**
 * ClickHouse-specific SQL compilation shared by every framework bridge.
 * The using class must extend its framework's query grammar, whose
 * inherited API (wrap, wrapTable, parameter, parameterize, columnize,
 * compileWheres, ...) this trait relies on.
 */
trait CompilesClickHouseQueries
{
    /**
     * Create a new grammar instance, registering the ClickHouse select
     * components. The parent property cannot be redeclared here — its type
     * differs between frameworks (untyped vs array) and a trait property
     * must match exactly — so the components are set at construction.
     * Laravel 11's grammar has no constructor, hence the guard.
     *
     * @param  mixed  $connection
     */
    public function __construct($connection = null)
    {
        if (method_exists(parent::class, '__construct')) {
            parent::__construct($connection);
        }

        $this->selectComponents = [
            'withQuery',
            'aggregate',
            'columns',
            'from',
            'sample',
            'indexHint',
            'joins',
            'arrayJoins',
            'prewheres',
            'wheres',
            'groups',
            'havings',
            'orders',
            'limitBy',
            'limit',
            'offset',
            'lock',
            'settings',
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|int  $seed
     */
    public function compileRandom($seed): string
    {
        return 'randCanonical()';
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     */
    public function compileSelect($query): string
    {
        if (($query->unions || $query->havings) && $query->aggregate) {
            return $this->compileUnionAggregate($query);
        }

        // If a "group limit" is in place, we will need to compile the SQL to use a
        // different syntax. This primarily supports limits on eager loads using
        // Eloquent. We'll also set the columns if they have not been defined.
        if (isset($query->groupLimit)) {
            if (is_null($query->columns)) {
                $query->columns = ['*'];
            }

            return $this->compileGroupLimit($query);
        }

        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        if ($query instanceof ClickHouseBuilder && count($query->arrayJoins)) {
            $aliases = [];

            foreach ($query->arrayJoins as $arrayJoin) {
                if ($arrayJoin['as'] && ! is_numeric($arrayJoin['as'])) {
                    $aliases[] = $arrayJoin['as'];
                }
            }

            /** @var array<mixed> $columns */
            $columns = array_merge($query->columns ?? [], $aliases);

            $query->columns = $columns;
        }

        // To compile the query, we'll spin through each component of the query and
        // see if that component exists. If it does we'll just call the compiler
        // function for the component which is responsible for making the SQL.
        $sql = trim($this->concatenate(
            $this->compileComponents($query))
        );

        if ($query->unions) {
            $sql = $this->wrapUnion($sql).' '.$this->compileUnions($query);
        }

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile an insert statement whose rows are sent in a ClickHouse
     * input format (e.g. JSONEachRow) instead of inline VALUES.
     *
     * @param  mixed  $query
     * @param  string[]  $columns
     */
    public function compileInsertUsingFormat($query, array $columns, Format $format): string
    {
        return sprintf(
            'insert into %s (%s) format %s',
            $this->wrapTable($query->from),
            $this->columnize($columns),
            $format->value
        );
    }

    /**
     * {@inheritDoc}
     *
     * Includes microseconds so that precision is preserved when binding to
     * a `DateTime64` column; ClickHouse silently truncates the fractional
     * part when the target column is a second-precision `DateTime`.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     */
    public function compileDelete($query, ?bool $lightweight = null, mixed $partition = null): string
    {
        $table = $this->wrapTable($query->from);

        $where = $this->compileWheres($query);

        return trim(
            isset($query->joins)
                ? $this->compileDeleteWithJoins($query, $table, $where, $lightweight, $partition)
                : $this->compileDeleteWithoutJoins($query, $table, $where, $lightweight, $partition)
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
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param array{
     *     function: string,
     *     columns: array<mixed>,
     * } $aggregate
     */
    protected function compileAggregate($query, $aggregate): string
    {
        $column = $this->columnize($aggregate['columns']);

        // If the query has a "distinct" constraint and we're not asking for all columns
        // we need to prepend "distinct" onto the column name so that the query takes
        // it into account when it performs the aggregating operations on the data.
        if (is_array($query->distinct)) {
            $column = 'distinct '.$this->columnize($query->distinct);
        } elseif ($query->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'select '.$aggregate['function'].'('.$column.') as '.$this->wrap('aggregate');
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $value
     */
    protected function wrapValue($value): string
    {
        if ($value !== '*') {
            return '`'.str_replace('`', '``', $value).'`';
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     *
     * @param array{
     *     'query': mixed,
     *     'type': string|null,
     * } $union
     */
    protected function compileUnion(array $union): string
    {
        return " {$union['type']} {$this->wrapUnion($union['query']->toSql())}";
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  mixed  $query
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'operator': string,
     *     'value': mixed,
     *     'boolean': string,
     * } $where
     */
    protected function dateBasedWhere($type, $query, $where): string
    {
        $function = [
            'date' => 'toDate',
            'day' => 'toDayOfMonth',
            'month' => 'toMonth',
            'year' => 'toYear',
            'time' => 'toTime',
        ][$type];

        if ($type === 'time' && ! $this->isExpression($where['value'])) {
            // @phpstan-ignore-next-line
            $where['value'] = $this->newExpression("toTime(toDateTime('1970-01-01 ".Carbon::parse($where['value'])->format('H:i:s')."'))");
        }

        $value = $this->parameter($where['value']);

        return $function.'('.$this->wrap($where['column']).') '.$where['operator'].' '.$value;
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param  string  $table
     * @param  string  $columns
     * @param  string  $where
     */
    protected function compileUpdateWithoutJoins($query, $table, $columns, $where): string
    {
        $cluster = ($query instanceof ClickHouseBuilder && $query->cluster) ? " on cluster {$this->wrapValue($query->cluster)}" : '';

        return "alter table {$table}{$cluster} update {$columns} {$where}";
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param  string  $table
     * @param  string  $columns
     * @param  string  $where
     */
    protected function compileUpdateWithJoins($query, $table, $columns, $where): string
    {
        throw new LogicException('ClickHouse does not support update with join, please use joinGet or dictGet instead.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param  string  $table
     * @param  string  $where
     */
    protected function compileDeleteWithoutJoins($query, $table, $where, ?bool $lightweight = null, mixed $partition = null): string
    {
        $connection = $query->connection;

        $cluster = ($query instanceof ClickHouseBuilder && $query->cluster) ? " on cluster {$this->wrapValue($query->cluster)}" : '';
        $partitionClause = $partition ? " in partition {$this->parameter($partition)}" : '';

        if ((! is_null($lightweight) && $lightweight) || $connection->getConfig('use_lightweight_delete')) {
            return "delete from {$table}{$cluster}{$partitionClause} {$where}";
        }

        return "alter table {$table}{$cluster} delete{$partitionClause} {$where}";
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param  string  $table
     * @param  string  $where
     */
    protected function compileDeleteWithJoins($query, $table, $where, ?bool $lightweight = null, mixed $partition = null): string
    {
        throw new LogicException('ClickHouse does not support delete with join.');
    }

    /**
     * Compile a "where empty" clause.
     *
     * @param  mixed  $query
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'boolean': string,
     * } $where
     */
    protected function whereEmpty($query, $where): string
    {
        return 'empty('.$this->wrap($where['column']).')';
    }

    /**
     * Compile a "where not empty" clause.
     *
     * @param  mixed  $query
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'boolean': string,
     * } $where
     */
    protected function whereNotEmpty($query, $where): string
    {
        return 'not empty('.$this->wrap($where['column']).')';
    }

    /**
     * {@inheritDoc}
     *
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'boolean': string,
     * } $having
     */
    protected function compileBasicHaving($having): string
    {
        return match ($having['type']) {
            'Empty' => $this->compileHavingEmpty($having),
            'NotEmpty' => $this->compileHavingNotEmpty($having),
            default => parent::compileBasicHaving($having),
        };
    }

    /**
     * Compile a having empty clause.
     *
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'boolean': string,
     * } $having
     */
    protected function compileHavingEmpty(array $having): string
    {
        return 'empty('.$this->wrap($having['column']).')';
    }

    /**
     * Compile a having not empty clause.
     *
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'boolean': string,
     * } $having
     */
    protected function compileHavingNotEmpty(array $having): string
    {
        return 'not empty('.$this->wrap($having['column']).')';
    }

    /**
     * Compile the "array join" portions of the query.
     *
     * @param  mixed  $query
     * @param array{
     *     'type': string,
     *     'column': mixed,
     *     'as': string|null,
     * }[] $arrayJoins
     */
    protected function compileArrayJoins($query, array $arrayJoins): string
    {
        if (count($arrayJoins) === 0) {
            return '';
        }

        $types = array_values(array_unique(array_map(
            static fn (array $arrayJoin) => $arrayJoin['type'],
            $arrayJoins
        )));

        if (count($types) > 1) {
            throw new LogicException('Cannot use array join and left array join at the same time.');
        }

        $conjunction = match ($types[0]) {
            'left' => 'left array join ',
            default => 'array join ',
        };

        $columns = array_map(function (array $arrayJoin) {
            $column = $this->wrap($arrayJoin['column']);
            $as = ! $this->isExpression($arrayJoin['column']) && $arrayJoin['as'] && ! is_numeric($arrayJoin['as'])
                ? " as {$this->wrapTable($arrayJoin['as'])}"
                : '';

            return $column.$as;
        }, $arrayJoins);

        return $conjunction.implode(', ', $columns);
    }

    /**
     * Compile the "with" portions of the query.
     *
     * @param  mixed  $query
     * @param array{
     *     'expression': mixed,
     *     'identifier': string,
     *     'subquery': bool,
     *     'recursive': bool,
     * }|null $withQuery
     */
    protected function compileWithQuery($query, ?array $withQuery): string
    {
        if (! $withQuery) {
            return '';
        }

        $conjunction = match ($withQuery['recursive']) {
            true => 'with recursive ',
            default => 'with ',
        };

        if ($withQuery['subquery']) {
            return "{$conjunction}{$this->wrap($withQuery['identifier'])} as ({$this->getValue($withQuery['expression'])})";
        }

        return "{$conjunction}{$this->parameter($withQuery['expression'])} as {$this->wrap($withQuery['identifier'])}";
    }

    /**
     * Compile the "settings" portions of the query.
     *
     * @param  mixed  $query
     * @param  array<string, int|float|bool|string>  $settings
     */
    protected function compileSettings($query, array $settings): string
    {
        if (count($settings) === 0) {
            return '';
        }

        $compiled = [];

        foreach ($settings as $key => $value) {
            $compiled[] = "{$this->wrap($key)} = {$this->parameter($value)}";
        }

        return 'settings '.implode(', ', $compiled);
    }

    /**
     * Compile the LIMIT n BY clause for the query.
     *
     * @param  mixed  $query
     * @param  array{'limit': int, 'columns': string[]}  $limitBy
     */
    protected function compileLimitBy($query, array $limitBy): string
    {
        return 'limit '.$limitBy['limit'].' by '.$this->columnize($limitBy['columns']);
    }

    /**
     * Compile the SAMPLE clause for the query.
     *
     * @param  mixed  $query
     * @param  array{'factor': float|int, 'offset': float|int|null}  $sample
     */
    protected function compileSample($query, array $sample): string
    {
        $sql = 'sample '.$sample['factor'];

        if (! is_null($sample['offset'])) {
            $sql .= ' offset '.$sample['offset'];
        }

        return $sql;
    }

    /**
     * Compile a GLOBAL IN clause.
     *
     * @param  mixed  $query
     * @param  array{'column': mixed, 'values': array<mixed>}  $where
     */
    protected function whereGlobalIn($query, $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' global in ('.$this->parameterize($where['values']).')';
        }

        return '0 = 1';
    }

    /**
     * Compile a GLOBAL NOT IN clause.
     *
     * @param  mixed  $query
     * @param  array{'column': mixed, 'values': array<mixed>}  $where
     */
    protected function whereGlobalNotIn($query, $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' global not in ('.$this->parameterize($where['values']).')';
        }

        return '1 = 1';
    }

    /**
     * Compile the PREWHERE clauses for the query.
     *
     * @param  mixed  $query
     * @param  array<int, array<string, mixed>>  $prewheres
     */
    protected function compilePrewheres($query, array $prewheres): string
    {
        if (empty($prewheres)) {
            return '';
        }

        $sql = [];

        foreach ($prewheres as $where) {
            /** @var string $boolean */
            $boolean = $where['boolean'];
            /** @var string $type */
            $type = $where['type'];

            $sql[] = $boolean.' '.$this->{"where{$type}"}($query, $where);
        }

        return 'prewhere '.$this->removeLeadingBoolean(implode(' ', $sql));
    }
}

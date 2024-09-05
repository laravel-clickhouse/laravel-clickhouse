<?php

namespace SwooleTW\ClickHouse\Laravel\Query;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use LogicException;

class Grammar extends BaseGrammar
{
    /**
     * The ClickHouse components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'indexHint',
        'joins',
        'arrayJoins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    ];

    /** {@inheritDoc} */
    public function compileRandom($seed): string
    {
        return 'randCanonical()';
    }

    /** {@inheritDoc} */
    public function compileSelect(BaseBuilder $query): string
    {
        if (($query->unions || $query->havings) && $query->aggregate) {
            return $this->compileUnionAggregate($query);
        }

        // If a "group limit" is in place, we will need to compile the SQL to use a
        // different syntax. This primarily supports limits on eager loads using
        // Eloquent. We'll also set the columns if they have not been defined.
        // @phpstan-ignore-next-line
        if (isset($query->groupLimit)) {
            if (is_null($query->columns)) {
                $query->columns = ['*'];
            }

            return $this->compileGroupLimit($query);
        }

        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        // @phpstan-ignore-next-line
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        if ($query instanceof Builder && count($query->arrayJoins)) {
            $query->columns = collect($query->arrayJoins)
                ->pluck('as')
                ->filter(function ($as) {
                    return $as && ! is_numeric($as);
                })
                ->reduce(function ($columns, $as) {
                    return array_merge($columns, [$as]);
                }, $query->columns);
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

    /** {@inheritDoc} */
    public function compileDelete(BaseBuilder $query, ?bool $lightweight = null): string
    {
        $table = $this->wrapTable($query->from);

        $where = $this->compileWheres($query);

        return trim(
            // @phpstan-ignore-next-line
            isset($query->joins)
                ? $this->compileDeleteWithJoins($query, $table, $where, $lightweight)
                : $this->compileDeleteWithoutJoins($query, $table, $where, $lightweight)
        );
    }

    /** {@inheritDoc} */
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
     *     'query': BaseBuilder,
     *     'all': boolean,
     * } $union
     */
    protected function compileUnion(array $union): string
    {
        $conjunction = $union['all'] ? ' union all ' : ' union distinct ';

        return $conjunction.$this->wrapUnion($union['query']->toSql());
    }

    /**
     * {@inheritDoc}
     *
     * @param array{
     *     'type': string,
     *     'column': Expression|string,
     *     'operator': string,
     *     'value': mixed,
     *     'boolean': string,
     * } $where
     */
    protected function dateBasedWhere($type, BaseBuilder $query, $where): string
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
            $where['value'] = new Expression("toTime(toDateTime('1970-01-01 ".Carbon::parse($where['value'])->format('H:i:s')."'))");
        }

        $value = $this->parameter($where['value']);

        return $function.'('.$this->wrap($where['column']).') '.$where['operator'].' '.$value;
    }

    /** {@inheritDoc} */
    protected function compileUpdateWithoutJoins(BaseBuilder $query, $table, $columns, $where): string
    {
        return "alter table {$table} update {$columns} {$where}";
    }

    /** {@inheritDoc} */
    protected function compileUpdateWithJoins(BaseBuilder $query, $table, $columns, $where): string
    {
        throw new LogicException('ClickHouse does not support update with join, please use joinGet or dictGet instead.');
    }

    /** {@inheritDoc} */
    protected function compileDeleteWithoutJoins(BaseBuilder $query, $table, $where, ?bool $lightweight = null): string
    {
        /** @var Connection $connection */
        $connection = $query->connection;

        if ((! is_null($lightweight) && $lightweight) || $connection->getConfig('use_lightweight_delete')) {
            return "delete from {$table} {$where}";
        }

        return "alter table {$table} delete {$where}";
    }

    /** {@inheritDoc} */
    protected function compileDeleteWithJoins(BaseBuilder $query, $table, $where, ?bool $lightweight = null): string
    {
        throw new LogicException('ClickHouse does not support delete with join.');
    }

    /**
     * Compile a "where empty" clause.
     *
     * @param array{
     *     'type': string,
     *     'column': Expression|string,
     *     'boolean': string,
     * } $where
     */
    protected function whereEmpty(BaseBuilder $query, $where): string
    {
        return 'empty('.$this->wrap($where['column']).')';
    }

    /**
     * Compile a "where not empty" clause.
     *
     * @param array{
     *     'type': string,
     *     'column': Expression|string,
     *     'boolean': string,
     * } $where
     */
    protected function whereNotEmpty(BaseBuilder $query, $where): string
    {
        return 'not empty('.$this->wrap($where['column']).')';
    }

    /**
     * {@inheritDoc}
     *
     * @param array{
     *     'type': string,
     *     'column': Expression|string,
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
     *     'column': Expression|string,
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
     *     'column': Expression|string,
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
     * @param array{
     *     'type': string,
     *     'column': Expression|string,
     *     'as': string|null,
     * }[] $arrayJoins
     */
    protected function compileArrayJoins(BaseBuilder $query, $arrayJoins): string
    {
        $arrayJoins = collect($arrayJoins);

        if ($arrayJoins->isEmpty()) {
            return '';
        }

        $types = $arrayJoins->pluck('type')->unique();

        if ($types->count() > 1) {
            throw new LogicException('Cannot use array join and left array join at the same time.');
        }

        $type = match ($types->first()) {
            'left' => 'left ',
            default => '',
        };

        return $type.'array join '.$arrayJoins->map(function ($arrayJoin) {
            $column = $this->wrap($arrayJoin['column']);
            $as = ! $this->isExpression($arrayJoin['column']) && $arrayJoin['as'] && ! is_numeric($arrayJoin['as'])
                ? " as {$this->wrapTable($arrayJoin['as'])}"
            : '';

            return $column.$as;
        })->implode(', ');
    }
}

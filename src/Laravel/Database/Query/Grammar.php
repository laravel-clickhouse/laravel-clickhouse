<?php

namespace SwooleTW\ClickHouse\Laravel\Database\Query;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use LogicException;

class Grammar extends BaseGrammar
{
    /** {@inheritDoc} */
    public function compileRandom($seed): string
    {
        return 'randCanonical()';
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
     *     'query': Builder,
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
    protected function dateBasedWhere($type, Builder $query, $where): string
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
    protected function compileUpdateWithoutJoins(Builder $query, $table, $columns, $where): string
    {
        return "alter table {$table} update {$columns} {$where}";
    }

    /** {@inheritDoc} */
    protected function compileUpdateWithJoins(Builder $query, $table, $columns, $where): string
    {
        throw new LogicException('ClickHouse does not support update with join, please use joinGet or dictGet instead.');
    }
}

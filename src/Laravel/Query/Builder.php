<?php

namespace SwooleTW\ClickHouse\Laravel\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder as BaseEloquentBuilder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use LogicException;
use SwooleTW\ClickHouse\Laravel\Eloquent\Model;

class Builder extends BaseBuilder
{
    /**
     * {@inheritDoc}
     *
     * @var Grammar
     */
    public $grammar;

    /**
     * {@inheritDoc}
     *
     * @var array<string, mixed[]>
     */
    public $bindings = [
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
        'unionOrder' => [],
        'arrayJoin' => [],
    ];

    /**
     * The array joins for the query.
     *
     * @var array{
     *     'type': string,
     *     'column': Expression|string,
     *     'as': string|null,
     * }[]
     */
    public $arrayJoins = [];

    /**
     * {@inheritDoc}
     *
     * NOTE: alias no function when using exists method, clickhouse's bug?
     */
    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        $results = $this->connection->select(
            $this->grammar->compileExists($this), $this->getBindings(), ! $this->useWritePdo
        );

        // If the results have rows, we will get the row and see if the exists column is a
        // boolean true. If there are no results for this query we will return false as
        // there are no rows for this query at all, and we can return that info here.
        if (isset($results[0])) {
            $results = (array) $results[0];

            // NOTE: due to alias no function, we can not get $results['exists'] directly,
            // so we use array_values to get the first value instead.
            return (bool) array_values($results)[0];
        }

        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        throw new LogicException('ClickHouse does not support insert get id.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>[]  $values
     * @param  string[]  $uniqueBy
     * @param  string[]  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('ClickHouse does not support upsert.');
    }

    /**
     * {@inheritDoc}
     */
    public function delete($id = null, ?bool $lightweight = null): int
    {
        // If an ID is passed to the method, we will set the where clause to check the
        // ID to let developers to simply and quickly remove a single row from this
        // database without manually specifying the "where" clauses on the query.
        if (! is_null($id)) {
            // @phpstan-ignore-next-line
            $this->where($this->from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->delete(
            $this->grammar->compileDelete($this, $lightweight), $this->cleanBindings(
                $this->grammar->prepareBindingsForDelete($this->bindings)
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function lock($value = true): static
    {
        throw new LogicException('ClickHouse does not support locking feature.');
    }

    /**
     * {@inheritDoc}
     */
    public function useIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes, please use preWhere instead.');
    }

    /**
     * {@inheritDoc}
     */
    public function forceIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes, please use preWhere instead.');
    }

    /**
     * {@inheritDoc}
     */
    public function ignoreIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes.');
    }

    /**
     * Add a "where empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function whereEmpty(string|array|ExpressionContract $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotEmpty' : 'Empty';

        foreach (Arr::wrap($columns) as $column) {
            $this->wheres[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add a "where not empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function whereNotEmpty(string|array|ExpressionContract $columns, string $boolean = 'and'): static
    {
        return $this->whereEmpty($columns, $boolean, true);
    }

    /**
     * Add a "or where empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function orWhereEmpty(string|array|ExpressionContract $columns): static
    {
        return $this->whereEmpty($columns, 'or');
    }

    /**
     * Add a "or where not empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function orWhereNotEmpty(string|array|ExpressionContract $columns): static
    {
        return $this->whereEmpty($columns, 'or', true);
    }

    /**
     * Add a "having empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function havingEmpty(string|array|ExpressionContract $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotEmpty' : 'Empty';

        foreach (Arr::wrap($columns) as $column) {
            $this->havings[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add a "having not empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function havingNotEmpty(string|array|ExpressionContract $columns, string $boolean = 'and'): static
    {
        return $this->havingEmpty($columns, $boolean, true);
    }

    /**
     * Add a "or having empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function orHavingEmpty(string|array|ExpressionContract $columns): static
    {
        return $this->havingEmpty($columns, 'or');
    }

    /**
     * Add a "or having not empty" clause to the query.
     *
     * @param  string|string[]|ExpressionContract  $columns
     */
    public function orHavingNotEmpty(string|array|ExpressionContract $columns): static
    {
        return $this->havingEmpty($columns, 'or', true);
    }

    /**
     * Add a "full join" clause to the query.
     */
    public function fullJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string|null $first = null,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        if ($first) {
            return $this->join($table, $first, $operator, $second, 'full');
        }

        // @phpstan-ignore-next-line
        $this->joins[] = $this->newJoinClause($this, 'full', $table);

        return $this;
    }

    /**
     * Add a subquery full join to the query.
     *
     * @param  Closure|self|BaseEloquentBuilder<Model>|string  $query
     */
    public function fullJoinSub(
        Closure|self|BaseEloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string|null $first = null,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        // @phpstan-ignore-next-line
        return $this->joinSub($query, $as, $first, $operator, $second, 'full');
    }

    /**
     * Add a "array join" clause to the query.
     *
     * @param  array<int|string, string>|self|BaseEloquentBuilder<Model>|string  $columns
     */
    public function arrayJoin(
        array|string|self|BaseEloquentBuilder $columns,
        ?string $as = null,
        string $type = 'inner'
    ): static {
        $columns = ! is_array($columns) && $as ? [$as => $columns] : Arr::wrap($columns);

        foreach ($columns as $as => $column) {
            if (is_numeric($as)) {
                $as = null;
            }

            if ($this->isQueryable($column)) {
                if (! $as) {
                    throw new LogicException('Array join with subquery must have an alias.');
                }

                $this->arrayJoinSub($column, $as);

                continue;
            }

            $this->arrayJoins[] = compact('type', 'column', 'as');
        }

        return $this;
    }

    /**
     * Add a "array join sub" clause to the query.
     *
     * @param  self|BaseEloquentBuilder<Model>|string  $query
     */
    public function arrayJoinSub(
        self|BaseEloquentBuilder|string $query,
        string $as,
        string $type = 'inner'
    ): static {
        [$query, $bindings] = $this->createSub($query);

        $column = new Expression('('.$query.') as '.$this->grammar->wrapTable($as));

        $this->addBinding($bindings, 'arrayJoin');
        $this->arrayJoins[] = compact('type', 'column', 'as');

        return $this;
    }

    /**
     * Add a "left array join" clause to the query.
     *
     * @param  array<int|string, string>|self|BaseEloquentBuilder<Model>|string  $columns
     */
    public function leftArrayJoin(
        array|string|self|BaseEloquentBuilder $columns,
        ?string $as = null
    ): static {
        return $this->arrayJoin($columns, $as, 'left');
    }

    /**
     * Add a "left array join sub" clause to the query.
     *
     * @param  self|BaseEloquentBuilder<Model>|string  $query
     */
    public function leftArrayJoinSub(
        self|BaseEloquentBuilder|string $query,
        string $as
    ): static {
        return $this->arrayJoinSub($query, $as, 'left');
    }

    /** {@inheritDoc} */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and'): static
    {
        if (! in_array($type, ['Date', 'Time']) && ! $value instanceof ExpressionContract) {
            // @phpstan-ignore-next-line
            $value = (int) $value;
        }

        return parent::addDateBasedWhere($type, $column, $operator, $value, $boolean);
    }
}

<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Laravel\Eloquent\Builder as EloquentBuilder;
use ClickHouse\Laravel\Eloquent\Model;
use Closure;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

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
        'withQuery' => [],
        'select' => [],
        'from' => [],
        'join' => [],
        'arrayJoin' => [],
        'partition' => [],
        'preWhere' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
        'unionOrder' => [],
        'settings' => [],
    ];

    /**
     * The array joins for the query.
     *
     * @var array{
     *     'type': string,
     *     'column': ExpressionContract|string,
     *     'as': string|null,
     * }[]
     */
    public $arrayJoins = [];

    /**
     * The array joins for the query.
     *
     * @var array{
     *     'expression': ExpressionContract|string,
     *     'identifier': string,
     *     'subquery': bool,
     *     'recursive': bool,
     * }
     */
    public $withQuery = null;

    /**
     * The settings for the query.
     *
     * @var array<string, int|float|bool|string>
     */
    public $settings = [];

    /**
     * The pre-where clauses for the query (PREWHERE).
     *
     * @var array<int, array<string, mixed>>
     */
    public $preWheres = [];

    /**
     * The cluster name for ON CLUSTER queries.
     */
    public ?string $cluster = null;

    /**
     * The sample factor for SAMPLE queries.
     *
     * @var array{'factor': float|int, 'offset': float|int|null}|null
     */
    public $sample = null;

    /**
     * The limit-by clause for LIMIT n BY queries.
     *
     * @var array{'limit': int, 'columns': string[]}|null
     */
    public $limitBy = null;

    /**
     * {@inheritDoc}
     */
    public function from($table, $as = null, bool $final = false): static
    {
        if (! $final) {
            return parent::from($table, $as);
        }

        if ($this->isQueryable($table)) {
            throw new LogicException('Select with final cannot be used with subquery.');
        }

        /** @var string $table */
        $this->from = new Expression($this->grammar->wrapTable($as ? "{$table} as {$as}" : $table).' final');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function fromSub($query, $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = "({$query})";

        if ($as) {
            $expression .= ' as '.$this->grammar->wrapTable($as);
        }

        return $this->fromRaw($expression, $bindings);
    }

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
     */
    public function union($query, $all = false, bool $distinct = false, string $type = 'union'): static
    {
        if ($query instanceof Closure) {
            $query($query = $this->newQuery());
        }

        if ($all && $distinct) {
            throw new LogicException('Cannot use all and distinct at the same time.');
        }

        if ($all) {
            $type .= ' all';
        }

        if ($distinct) {
            $type .= ' distinct';
        }

        $this->unions[] = compact('query', 'type');

        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }

    /**
     * Add a union distinct statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>  $query
     */
    public function unionDistinct(Closure|self|EloquentBuilder $query): static
    {
        return $this->union($query, distinct: true);
    }

    /**
     * Add a intersect statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>  $query
     */
    public function intersect(Closure|self|EloquentBuilder $query, bool $distinct = false): static
    {
        return $this->union($query, distinct: $distinct, type: 'intersect');
    }

    /**
     * Add a intersect distinct statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>  $query
     */
    public function intersectDistinct(Closure|self|EloquentBuilder $query): static
    {
        return $this->intersect($query, true);
    }

    /**
     * Add a except statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>  $query
     */
    public function except(Closure|self|EloquentBuilder $query, bool $distinct = false): static
    {
        return $this->union($query, distinct: $distinct, type: 'except');
    }

    /**
     * Add a except distinct statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>  $query
     */
    public function exceptDistinct(Closure|self|EloquentBuilder $query): static
    {
        return $this->except($query, true);
    }

    /**
     * Add a "with" clause to the query.
     *
     * @param  ExpressionContract|string|self|EloquentBuilder<Model>  $expression
     */
    public function withQuery(
        ExpressionContract|string|self|EloquentBuilder $expression,
        string $identifier,
        bool $subquery = false
    ): static {
        $recursive = false;

        if ($this->isQueryable($expression)) {
            /** @var self|EloquentBuilder<Model> $expression */
            [$query, $bindings] = $this->createSub($expression);

            $expression = new Expression('('.$query.')');

            $this->withQuery = compact('expression', 'identifier', 'subquery', 'recursive');

            $this->addBinding($bindings, 'withQuery');

            return $this;
        }

        /** @var ExpressionContract|string $expression */
        $this->withQuery = compact('expression', 'identifier', 'subquery', 'recursive');

        if (! $expression instanceof ExpressionContract) {
            $this->addBinding($expression, 'withQuery');
        }

        return $this;
    }

    /**
     * Add a "raw with" clause to the query.
     *
     * @param  array<string|number>  $bindings
     */
    public function withQueryRaw(
        string $expression,
        string $identifier,
        array $bindings = [],
        bool $subquery = false,
        bool $recursive = false,
    ): static {
        $this->withQuery = compact('expression', 'identifier', 'subquery', 'recursive');

        $this->addBinding($bindings, 'withQuery');

        return $this;
    }

    /**
     * Add a "with subquery" clause to the query.
     *
     * @param  self|EloquentBuilder<Model>  $expression
     */
    public function withQuerySub(
        self|EloquentBuilder $expression,
        string $identifier,
        bool $recursive = false,
    ): static {
        [$query, $bindings] = $this->createSub($expression);

        return $this->withQueryRaw($query, $identifier, $bindings, true, $recursive);
    }

    /**
     * Add a "with recursive query" clause to the query.
     *
     * @param  self|EloquentBuilder<Model>  $expression
     */
    public function withQueryRecursive(
        self|EloquentBuilder $expression,
        string $identifier,
    ): static {
        return $this->withQuerySub($expression, $identifier, true);
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
    public function delete($id = null, ?bool $lightweight = null, mixed $partition = null): int
    {
        // If an ID is passed to the method, we will set the where clause to check the
        // ID to let developers to simply and quickly remove a single row from this
        // database without manually specifying the "where" clauses on the query.
        if (! is_null($id)) {
            // @phpstan-ignore-next-line
            $this->where($this->from.'.id', '=', $id);
        }

        if ($partition && ! $partition instanceof ExpressionContract) {
            $this->addBinding($partition, 'partition');
        }

        $this->applyBeforeQueryCallbacks();

        $result = $this->connection->delete(
            $this->grammar->compileDelete($this, $lightweight, $partition), $this->cleanBindings(
                $this->grammar->prepareBindingsForDelete($this->bindings)
            )
        );

        $this->setBindings([], 'partition');

        return $result;
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
     * Add a PREWHERE clause to the query.
     *
     * @param  Closure|string|array<mixed>|ExpressionContract  $column
     */
    public function preWhere(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof ConditionExpression) {
            $type = 'Expression';
            $this->preWheres[] = compact('type', 'column', 'boolean');

            return $this;
        }

        if (is_array($column)) {
            return $this->addArrayOfPreWheres($column, $boolean);
        }

        // @phpstan-ignore-next-line
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        if ($column instanceof Closure && is_null($operator)) {
            return $this->preWhereNested($column, $boolean);
        }

        if ($this->isQueryable($column) && ! is_null($operator)) {
            /** @var Closure|BaseBuilder|EloquentBuilder<Model> $column */
            [$sub, $bindings] = $this->createSub($column);

            return $this->addBinding($bindings, 'preWhere')
                ->preWhere(new Expression('('.$sub.')'), $operator, $value, $boolean);
        }

        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        if ($this->isQueryable($value)) {
            return $this->preWhereSub($column, $operator, $value, $boolean);
        }

        if (is_null($value)) {
            /** @var string $column */
            return $this->preWhereNull($column, $boolean, $operator !== '=');
        }

        $type = 'Basic';

        $columnString = ($column instanceof ExpressionContract)
            ? $this->grammar->getValue($column)
            : $column;

        /** @var string $columnString */
        if (str_contains($columnString, '->') && is_bool($value)) {
            $value = new Expression($value ? 'true' : 'false');

            if (is_string($column)) {
                $type = 'JsonBoolean';
            }
        }

        if ($this->isBitwiseOperator($operator)) {
            $type = 'Bitwise';
        }

        $this->preWheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($this->flattenValue($value), 'preWhere');
        }

        return $this;
    }

    /**
     * Add an array of PREWHERE clauses to the query.
     *
     * @param  array<mixed>  $column
     */
    protected function addArrayOfPreWheres(array $column, string $boolean): static
    {
        return $this->preWhereNested(function (self $query) use ($column, $boolean) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $arguments = array_values($value);
                    $query->where($arguments[0], $arguments[1] ?? null, $arguments[2] ?? null, $boolean);
                } else {
                    $query->where($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }

    /**
     * Add a nested PREWHERE clause to the query.
     */
    protected function preWhereNested(Closure $callback, string $boolean = 'and'): static
    {
        $callback($query = $this->forNestedWhere());

        if (count($query->wheres)) {
            $type = 'Nested';
            $this->preWheres[] = compact('type', 'query', 'boolean');
            $this->addBinding($query->getRawBindings()['where'], 'preWhere');
        }

        return $this;
    }

    /**
     * Add a full sub-select PREWHERE clause to the query.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $callback
     */
    protected function preWhereSub(mixed $column, string $operator, mixed $callback, string $boolean): static
    {
        $type = 'Sub';

        if ($callback instanceof Closure) {
            $callback($query = $this->forSubQuery());
        } else {
            $query = $callback instanceof EloquentBuilder ? $callback->toBase() : $callback;
        }

        $this->preWheres[] = compact('type', 'column', 'operator', 'query', 'boolean');
        $this->addBinding($query->getBindings(), 'preWhere');

        return $this;
    }

    /**
     * Add an OR PREWHERE clause to the query.
     *
     * @param  Closure|string|array<mixed>|ExpressionContract  $column
     */
    public function orPreWhere(mixed $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->preWhere($column, $operator, $value, 'or');
    }

    /**
     * Add a raw PREWHERE clause to the query.
     *
     * @param  array<mixed>  $bindings
     */
    public function preWhereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->preWheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];
        $this->addBinding((array) $bindings, 'preWhere');

        return $this;
    }

    /**
     * Add a raw OR PREWHERE clause to the query.
     *
     * @param  array<mixed>  $bindings
     */
    public function orPreWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->preWhereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a PREWHERE IN clause to the query.
     *
     * @param  Closure|BaseBuilder|Arrayable<int|string, mixed>|array<mixed>  $values
     */
    public function preWhereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotIn' : 'In';

        if ($this->isQueryable($values)) {
            /** @var Closure|BaseBuilder|EloquentBuilder<Model> $values */
            [$query, $bindings] = $this->createSub($values);
            $values = [new Expression($query)];
            $this->addBinding($bindings, 'preWhere');
        }

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->preWheres[] = compact('type', 'column', 'values', 'boolean');

        /** @var array<int|string, mixed> $values */
        if (count($values) !== count(Arr::flatten($values, 1))) {
            throw new InvalidArgumentException('Nested arrays may not be passed to preWhereIn method.');
        }

        $this->addBinding($this->cleanBindings($values), 'preWhere');

        return $this;
    }

    /**
     * Add a PREWHERE NOT IN clause to the query.
     *
     * @param  Closure|BaseBuilder|Arrayable<int|string, mixed>|array<mixed>  $values
     */
    public function preWhereNotIn(string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /**
     * Add a PREWHERE NULL clause to the query.
     *
     * @param  string|string[]  $columns
     */
    public function preWhereNull(string|array $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotNull' : 'Null';

        foreach (Arr::wrap($columns) as $column) {
            $this->preWheres[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add a PREWHERE NOT NULL clause to the query.
     *
     * @param  string|string[]  $columns
     */
    public function preWhereNotNull(string|array $columns, string $boolean = 'and'): static
    {
        return $this->preWhereNull($columns, $boolean, true);
    }

    /**
     * Set the ON CLUSTER clause for ALTER TABLE / DELETE / UPDATE queries.
     */
    public function cluster(string $cluster): static
    {
        $this->cluster = $cluster;

        return $this;
    }

    /**
     * Add a SAMPLE clause to the query.
     *
     * @param  float|int  $factor  Sampling fraction (e.g. 0.1) or absolute row count (e.g. 1000)
     * @param  float|int|null  $offset  Sampling offset fraction
     */
    public function sample(float|int $factor, float|int|null $offset = null): static
    {
        $this->sample = compact('factor', 'offset');

        return $this;
    }

    /**
     * Add a LIMIT n BY clause to the query.
     *
     * @param  string|string[]  $columns
     */
    public function limitBy(int $limit, string|array $columns): static
    {
        $this->limitBy = ['limit' => $limit, 'columns' => Arr::wrap($columns)];

        return $this;
    }

    /**
     * Add a GLOBAL IN clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|array<mixed>  $values
     */
    public function whereGlobalIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'GlobalNotIn' : 'GlobalIn';

        if ($this->isQueryable($values)) {
            /** @var Closure|self|EloquentBuilder<Model> $values */
            [$query, $bindings] = $this->createSub($values);
            $values = [new Expression($query)];
            $this->addBinding($bindings, 'where');
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        if (! $values instanceof ExpressionContract) {
            /** @var array<mixed> $values */
            $this->addBinding($this->cleanBindings($values), 'where');
        }

        return $this;
    }

    /**
     * Add a GLOBAL NOT IN clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|array<mixed>  $values
     */
    public function whereGlobalNotIn(string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    /**
     * Add an OR GLOBAL IN clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|array<mixed>  $values
     */
    public function orWhereGlobalIn(string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or');
    }

    /**
     * Add an OR GLOBAL NOT IN clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|array<mixed>  $values
     */
    public function orWhereGlobalNotIn(string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or', true);
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
     * Add a "inner join" clause to the query.
     */
    public function innerJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'inner');
    }

    /**
     * Add a subquery inner join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function innerJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'inner');
    }

    /**
     * Add a "inner any join" clause to the query.
     */
    public function innerAnyJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'inner any');
    }

    /**
     * Add a subquery inner any join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function innerAnyJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'inner any');
    }

    /**
     * Add a "left any join" clause to the query.
     */
    public function leftAnyJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'left any');
    }

    /**
     * Add a subquery left any join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function leftAnyJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left any');
    }

    /**
     * Add a "right any join" clause to the query.
     */
    public function rightAnyJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right any');
    }

    /**
     * Add a subquery right any join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function rightAnyJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right any');
    }

    /**
     * Add a "full join" clause to the query.
     */
    public function fullJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'full');
    }

    /**
     * Add a subquery full join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function fullJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'full');
    }

    /**
     * Add a "semi join" clause to the query.
     */
    public function semiJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'semi');
    }

    /**
     * Add a subquery semi join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function semiJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'semi');
    }

    /**
     * Add a "right semi join" clause to the query.
     */
    public function rightSemiJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right semi');
    }

    /**
     * Add a subquery right semi join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function rightSemiJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right semi');
    }

    /**
     * Add a "anti join" clause to the query.
     */
    public function antiJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'anti');
    }

    /**
     * Add a subquery anti join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function antiJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'anti');
    }

    /**
     * Add a "right anti join" clause to the query.
     */
    public function rightAntiJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right anti');
    }

    /**
     * Add a subquery right anti join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function rightAntiJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right anti');
    }

    /**
     * Add a "asof join" clause to the query.
     */
    public function asofJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'asof');
    }

    /**
     * Add a subquery asof join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function asofJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'asof');
    }

    /**
     * Add a "left asof join" clause to the query.
     */
    public function leftAsofJoin(
        string|ExpressionContract $table,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'left asof');
    }

    /**
     * Add a subquery left asof join to the query.
     *
     * @param  Closure|self|EloquentBuilder<Model>|string  $query
     */
    public function leftAsofJoinSub(
        Closure|self|EloquentBuilder|string $query,
        string $as,
        Closure|ExpressionContract|string $first,
        ?string $operator = null,
        ExpressionContract|string|null $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left asof');
    }

    /**
     * Add a "array join" clause to the query.
     *
     * @param  array<int|string, string>|self|EloquentBuilder<Model>|string  $columns
     */
    public function arrayJoin(
        array|string|self|EloquentBuilder $columns,
        ?string $as = null,
        string $type = 'inner'
    ): static {
        $columns = ! is_array($columns) && $as ? [$as => $columns] : Arr::wrap($columns);

        foreach ($columns as $as => $column) {
            if (is_numeric($as)) {
                $as = null;
            }

            if (! $this->isQueryable($column)) {
                $this->arrayJoins[] = compact('type', 'column', 'as');

                continue;
            }

            if (! $as) {
                throw new LogicException('Array join with subquery must have an alias.');
            }

            $this->arrayJoinSub($column, $as);
        }

        return $this;
    }

    /**
     * Add a "array join sub" clause to the query.
     *
     * @param  self|EloquentBuilder<Model>|string  $query
     */
    public function arrayJoinSub(
        self|EloquentBuilder|string $query,
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
     * @param  array<int|string, string>|self|EloquentBuilder<Model>|string  $columns
     */
    public function leftArrayJoin(
        array|string|self|EloquentBuilder $columns,
        ?string $as = null
    ): static {
        return $this->arrayJoin($columns, $as, 'left');
    }

    /**
     * Add a "left array join sub" clause to the query.
     *
     * @param  self|EloquentBuilder<Model>|string  $query
     */
    public function leftArrayJoinSub(
        self|EloquentBuilder|string $query,
        string $as
    ): static {
        return $this->arrayJoinSub($query, $as, 'left');
    }

    /**
     * Add a "settings" clause to the query.
     *
     * @param  string|array<string, int|float|bool|string>  $key
     */
    public function settings(string|array $key, int|float|bool|string|null $value = null): static
    {
        if (is_string($key) && is_null($value)) {
            throw new LogicException('Value is required for settings.');
        }

        $settings = is_array($key) ? $key : [$key => $value];

        foreach ($settings as $key => $value) {
            $index = array_search($key, array_keys($this->settings));
            $override = $index !== false;

            $this->settings[$key] = $value;

            if ($override) {
                $this->bindings['settings'][$index] = $this->castBinding($value);

                continue;
            }

            $this->addBinding($value, 'settings');
        }

        return $this;
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

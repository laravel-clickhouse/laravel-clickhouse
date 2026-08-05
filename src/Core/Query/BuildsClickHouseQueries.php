<?php

namespace ClickHouse\Core\Query;

use ClickHouse\Core\Enums\Format;
use ClickHouse\Core\Support\JsonEachRowEncoder;
use Closure;
use LogicException;

/**
 * ClickHouse-specific query builder behaviour shared by every framework
 * bridge. The using class must extend its framework's query builder, whose
 * inherited API (createSub, join, joinSub, addBinding, isQueryable, ...)
 * this trait relies on.
 */
trait BuildsClickHouseQueries
{
    /**
     * The array joins for the query.
     *
     * @var list<array{
     *     type: string,
     *     column: mixed,
     *     as: string|null,
     * }>
     */
    public $arrayJoins = [];

    /**
     * The "with" clause for the query.
     *
     * @var array{
     *     'expression': mixed,
     *     'identifier': string,
     *     'subquery': bool,
     *     'recursive': bool,
     * }|null
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
    public $prewheres = [];

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
     * Create a new query builder instance, registering the ClickHouse
     * binding slots. The parent property cannot be redeclared here — its
     * type differs between frameworks (untyped vs array) and a trait
     * property must match exactly — so the slots are merged at
     * construction instead.
     *
     * @param  mixed  $connection
     * @param  mixed  $grammar
     * @param  mixed  $processor
     */
    public function __construct($connection, $grammar = null, $processor = null)
    {
        parent::__construct($connection, $grammar, $processor);

        $this->bindings = [
            'withQuery' => [],
            'select' => [],
            'from' => [],
            'join' => [],
            'arrayJoin' => [],
            'partition' => [],
            'prewhere' => [],
            'where' => [],
            'groupBy' => [],
            'having' => [],
            'order' => [],
            'union' => [],
            'unionOrder' => [],
            'settings' => [],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $table
     * @param  string|null  $as
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
        $this->from = $this->newExpression($this->grammar->wrapTable($as ? "{$table} as {$as}" : $table).' final');

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $query
     * @param  string  $as
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
     *
     * @param  mixed  $query
     * @param  bool  $all
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
     * @param  mixed  $query  Closure, query builder or Eloquent builder
     */
    public function unionDistinct($query): static
    {
        return $this->union($query, distinct: true);
    }

    /**
     * Add a intersect statement to the query.
     *
     * @param  mixed  $query  Closure, query builder or Eloquent builder
     */
    public function intersect($query, bool $distinct = false): static
    {
        return $this->union($query, distinct: $distinct, type: 'intersect');
    }

    /**
     * Add a intersect distinct statement to the query.
     *
     * @param  mixed  $query  Closure, query builder or Eloquent builder
     */
    public function intersectDistinct($query): static
    {
        return $this->intersect($query, true);
    }

    /**
     * Add a except statement to the query.
     *
     * @param  mixed  $query  Closure, query builder or Eloquent builder
     */
    public function except($query, bool $distinct = false): static
    {
        return $this->union($query, distinct: $distinct, type: 'except');
    }

    /**
     * Add a except distinct statement to the query.
     *
     * @param  mixed  $query  Closure, query builder or Eloquent builder
     */
    public function exceptDistinct($query): static
    {
        return $this->except($query, true);
    }

    /**
     * Add a "with" clause to the query.
     *
     * @param  mixed  $expression  Expression, string, query builder or Eloquent builder
     */
    public function withQuery(
        $expression,
        string $identifier,
        bool $subquery = false
    ): static {
        $recursive = false;

        if ($this->isQueryable($expression)) {
            [$query, $bindings] = $this->createSub($expression);

            $expression = $this->newExpression('('.$query.')');

            $this->withQuery = compact('expression', 'identifier', 'subquery', 'recursive');

            $this->addBinding($bindings, 'withQuery');

            return $this;
        }

        $this->withQuery = compact('expression', 'identifier', 'subquery', 'recursive');

        if (! $this->isExpressionValue($expression)) {
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
     * @param  mixed  $expression  Query builder or Eloquent builder
     */
    public function withQuerySub(
        $expression,
        string $identifier,
        bool $recursive = false,
    ): static {
        [$query, $bindings] = $this->createSub($expression);

        return $this->withQueryRaw($query, $identifier, $bindings, true, $recursive);
    }

    /**
     * Add a "with recursive query" clause to the query.
     *
     * @param  mixed  $expression  Query builder or Eloquent builder
     */
    public function withQueryRecursive(
        $expression,
        string $identifier,
    ): static {
        return $this->withQuerySub($expression, $identifier, true);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $values
     * @param  Format  $format  The ClickHouse input format used to encode the rows.
     */
    public function insert(array $values, Format $format = Format::Values): bool
    {
        return match ($format) {
            // Laravel's parent is undeclared (bool at runtime); the cast
            // keeps this trait's : bool honest under both frameworks.
            Format::Values => (bool) parent::insert($values),
            Format::JSONEachRow => $this->insertJsonEachRow($values),
        };
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
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
     *
     * @param  mixed  $id
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

        if ($partition && ! $this->isExpressionValue($partition)) {
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
     *
     * @param  mixed  $value
     */
    public function lock($value = true): static
    {
        throw new LogicException('ClickHouse does not support locking feature.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $index
     */
    public function useIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $index
     */
    public function forceIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes.');
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $index
     */
    public function ignoreIndex($index): static
    {
        throw new LogicException('ClickHouse does not support specify indexes.');
    }

    /**
     * Add a PREWHERE clause to the query.
     *
     * @param  mixed  $column  Closure, string, array or expression
     */
    public function prewhere(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->redirectToPrewheres(fn () => $this->where($column, $operator, $value, $boolean));
    }

    /**
     * Add an OR PREWHERE clause to the query.
     *
     * @param  mixed  $column  Closure, string, array or expression
     */
    public function orPrewhere(mixed $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->prewhere($column, $operator, $value, 'or');
    }

    /**
     * Add a raw PREWHERE clause to the query.
     *
     * @param  array<mixed>  $bindings
     */
    public function prewhereRaw(mixed $sql, array $bindings = [], string $boolean = 'and'): static
    {
        return $this->redirectToPrewheres(fn () => $this->whereRaw($sql, $bindings, $boolean));
    }

    /**
     * Add a raw OR PREWHERE clause to the query.
     *
     * @param  array<mixed>  $bindings
     */
    public function orPrewhereRaw(mixed $sql, array $bindings = []): static
    {
        return $this->prewhereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a PREWHERE IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function prewhereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        return $this->redirectToPrewheres(fn () => $this->whereIn($column, $values, $boolean, $not));
    }

    /**
     * Add a PREWHERE NOT IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function prewhereNotIn(string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->prewhereIn($column, $values, $boolean, true);
    }

    /**
     * Add a PREWHERE NULL clause to the query.
     *
     * @param  string|string[]  $columns
     */
    public function prewhereNull(string|array $columns, string $boolean = 'and', bool $not = false): static
    {
        return $this->redirectToPrewheres(fn () => $this->whereNull($columns, $boolean, $not));
    }

    /**
     * Add a PREWHERE NOT NULL clause to the query.
     *
     * @param  string|string[]  $columns
     */
    public function prewhereNotNull(string|array $columns, string $boolean = 'and'): static
    {
        return $this->prewhereNull($columns, $boolean, true);
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
        $this->limitBy = ['limit' => $limit, 'columns' => is_array($columns) ? $columns : [$columns]];

        return $this;
    }

    /**
     * Add a GLOBAL IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function whereGlobalIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'GlobalNotIn' : 'GlobalIn';

        if ($this->isQueryable($values)) {
            [$query, $bindings] = $this->createSub($values);
            $values = [$this->newExpression($query)];
            $this->addBinding($bindings, 'where');
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        if (! $this->isExpressionValue($values)) {
            /** @var array<mixed> $values */
            $this->addBinding($this->cleanBindings($values), 'where');
        }

        return $this;
    }

    /**
     * Add a GLOBAL NOT IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function whereGlobalNotIn(string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    /**
     * Add an OR GLOBAL IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function orWhereGlobalIn(string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or');
    }

    /**
     * Add an OR GLOBAL NOT IN clause to the query.
     *
     * @param  mixed  $values  Closure, query builder, Eloquent builder or array
     */
    public function orWhereGlobalNotIn(string $column, mixed $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or', true);
    }

    /**
     * Add a "where empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function whereEmpty($columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotEmpty' : 'Empty';

        foreach ((is_array($columns) ? $columns : [$columns]) as $column) {
            $this->wheres[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add a "where not empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function whereNotEmpty($columns, string $boolean = 'and'): static
    {
        return $this->whereEmpty($columns, $boolean, true);
    }

    /**
     * Add a "or where empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function orWhereEmpty($columns): static
    {
        return $this->whereEmpty($columns, 'or');
    }

    /**
     * Add a "or where not empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function orWhereNotEmpty($columns): static
    {
        return $this->whereEmpty($columns, 'or', true);
    }

    /**
     * Add a "having empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function havingEmpty($columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotEmpty' : 'Empty';

        foreach ((is_array($columns) ? $columns : [$columns]) as $column) {
            $this->havings[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add a "having not empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function havingNotEmpty($columns, string $boolean = 'and'): static
    {
        return $this->havingEmpty($columns, $boolean, true);
    }

    /**
     * Add a "or having empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function orHavingEmpty($columns): static
    {
        return $this->havingEmpty($columns, 'or');
    }

    /**
     * Add a "or having not empty" clause to the query.
     *
     * @param  mixed  $columns  Column name, array of column names or expression
     */
    public function orHavingNotEmpty($columns): static
    {
        return $this->havingEmpty($columns, 'or', true);
    }

    /**
     * Add a "inner join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function innerJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'inner');
    }

    /**
     * Add a subquery inner join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function innerJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'inner');
    }

    /**
     * Add a "inner any join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function innerAnyJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'inner any');
    }

    /**
     * Add a subquery inner any join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function innerAnyJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'inner any');
    }

    /**
     * Add a "left any join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function leftAnyJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'left any');
    }

    /**
     * Add a subquery left any join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function leftAnyJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left any');
    }

    /**
     * Add a "right any join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightAnyJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right any');
    }

    /**
     * Add a subquery right any join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightAnyJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right any');
    }

    /**
     * Add a "full join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function fullJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'full');
    }

    /**
     * Add a subquery full join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function fullJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'full');
    }

    /**
     * Add a "semi join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function semiJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'semi');
    }

    /**
     * Add a subquery semi join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function semiJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'semi');
    }

    /**
     * Add a "right semi join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightSemiJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right semi');
    }

    /**
     * Add a subquery right semi join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightSemiJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right semi');
    }

    /**
     * Add a "anti join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function antiJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'anti');
    }

    /**
     * Add a subquery anti join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function antiJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'anti');
    }

    /**
     * Add a "right anti join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightAntiJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'right anti');
    }

    /**
     * Add a subquery right anti join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function rightAntiJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right anti');
    }

    /**
     * Add a "asof join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function asofJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'asof');
    }

    /**
     * Add a subquery asof join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function asofJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'asof');
    }

    /**
     * Add a "left asof join" clause to the query.
     *
     * @param  mixed  $table
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function leftAsofJoin(
        $table,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->join($table, $first, $operator, $second, 'left asof');
    }

    /**
     * Add a subquery left asof join to the query.
     *
     * @param  mixed  $query
     * @param  mixed  $first
     * @param  mixed  $second
     */
    public function leftAsofJoinSub(
        $query,
        string $as,
        $first,
        ?string $operator = null,
        $second = null
    ): static {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left asof');
    }

    /**
     * Add a "array join" clause to the query.
     *
     * @param  mixed  $columns  Column name(s), query builder or Eloquent builder
     */
    public function arrayJoin(
        $columns,
        ?string $as = null,
        string $type = 'inner'
    ): static {
        $columns = ! is_array($columns) && $as ? [$as => $columns] : (is_array($columns) ? $columns : [$columns]);

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
     * @param  mixed  $query  Query builder, Eloquent builder or string
     */
    public function arrayJoinSub(
        $query,
        string $as,
        string $type = 'inner'
    ): static {
        [$query, $bindings] = $this->createSub($query);

        $column = $this->newExpression('('.$query.') as '.$this->grammar->wrapTable($as));

        $this->addBinding($bindings, 'arrayJoin');
        $this->arrayJoins[] = compact('type', 'column', 'as');

        return $this;
    }

    /**
     * Add a "left array join" clause to the query.
     *
     * @param  mixed  $columns  Column name(s), query builder or Eloquent builder
     */
    public function leftArrayJoin(
        $columns,
        ?string $as = null
    ): static {
        return $this->arrayJoin($columns, $as, 'left');
    }

    /**
     * Add a "left array join sub" clause to the query.
     *
     * @param  mixed  $query  Query builder, Eloquent builder or string
     */
    public function leftArrayJoinSub(
        $query,
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

    /**
     * {@inheritDoc}
     *
     * The base builder declares $type as a literal-string enum of the
     * core binding slots. ClickHouse adds its own slots (arrayJoin,
     * partition, settings, withQuery) via the overridden $bindings
     * property, so the type is widened to plain string here.
     *
     * @param  mixed  $value
     * @param  string  $type
     */
    public function addBinding($value, $type = 'where'): static
    {
        return parent::addBinding($value, $type);
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $bindings
     * @param  string  $type
     */
    public function setBindings(array $bindings, $type = 'where'): static
    {
        return parent::setBindings($bindings, $type);
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
     * Determine whether the given value is a framework expression.
     */
    protected function isExpressionValue(mixed $value): bool
    {
        // instanceof against a constant expression requires PHP 8.3; the
        // class-string is read into a local to stay compatible with 8.2.
        $contract = static::EXPRESSION_CONTRACT;

        return $value instanceof $contract;
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $values
     */
    protected function insertJsonEachRow(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $firstKey = array_key_first($values);

        if (is_string($firstKey) && $this->containsOnlyAssociativeArrays($values)) {
            throw new LogicException(
                'Formatted insert rows are ambiguous. Use array_values() for multiple rows or wrap a single row in another array.'
            );
        }

        if (is_string($firstKey)) {
            $values = [$values];
        }

        if (is_int($firstKey)) {
            $values = array_values($values);
        }

        /** @var array<string, mixed> $first */
        $first = reset($values);

        /** @var list<array<string, mixed>> $values */
        foreach ($values as $row) {
            if (! is_array($row)) {
                throw new LogicException('All rows passed to a formatted insert must be arrays.');
            }

            // ClickHouse silently drops unknown keys and defaults missing ones
            // in JSONEachRow input, so a key mismatch must fail loudly here.
            if (array_diff_key($row, $first) !== [] || array_diff_key($first, $row) !== []) {
                throw new LogicException('All rows passed to a formatted insert must have the same keys.');
            }
        }

        $this->applyBeforeQueryCallbacks();

        // @phpstan-ignore-next-line
        return $this->connection->insertRawPayload(
            $this->grammar->compileInsertUsingFormat($this, array_keys($first), Format::JSONEachRow),
            (new JsonEachRowEncoder)->encode($values)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  mixed  $column
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and'): static
    {
        if (! in_array($type, ['Date', 'Time']) && ! $this->isExpressionValue($value)) {
            // @phpstan-ignore-next-line
            $value = (int) $value;
        }

        return parent::addDateBasedWhere($type, $column, $operator, $value, $boolean);
    }

    /**
     * @param  array<mixed>  $values
     */
    private function containsOnlyAssociativeArrays(array $values): bool
    {
        return array_filter(
            $values,
            fn (mixed $value): bool => ! is_array($value) || array_is_list($value)
        ) === [];
    }

    /**
     * Temporarily swap wheres/bindings to the preWhere arrays and run the callback.
     */
    private function redirectToPrewheres(callable $callback): static
    {
        [$this->wheres, $this->prewheres] = [$this->prewheres, $this->wheres];
        [$this->bindings['where'], $this->bindings['prewhere']] = [$this->bindings['prewhere'], $this->bindings['where']];

        try {
            $callback();
        } finally {
            // Swap back after the callback so the builder is in a consistent state for the next call.
            [$this->wheres, $this->prewheres] = [$this->prewheres, $this->wheres];
            [$this->bindings['where'], $this->bindings['prewhere']] = [$this->bindings['prewhere'], $this->bindings['where']];
        }

        return $this;
    }
}

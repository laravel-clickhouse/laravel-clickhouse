<?php

namespace SwooleTW\ClickHouse\Laravel\Database\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder as BaseBuilder;
use LogicException;

class Builder extends BaseBuilder
{
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
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('ClickHouse does not support upsert.');
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

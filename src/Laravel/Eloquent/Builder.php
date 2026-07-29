<?php

namespace ClickHouse\Laravel\Eloquent;

use ClickHouse\Laravel\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * @template TModel of Model
 *
 * @extends BaseBuilder<TModel>
 */
class Builder extends BaseBuilder
{
    /**
     * {@inheritDoc}
     *
     * @var QueryBuilder
     */
    protected $query;

    /** {@inheritDoc} */
    public function delete(?bool $lightweight = null, mixed $partition = null)
    {
        // @phpstan-ignore-next-line
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        // @phpstan-ignore-next-line
        return $this->toBase()->delete(null, $lightweight, $partition);
    }

    /** {@inheritDoc} */
    public function forceDelete(?bool $lightweight = null, mixed $partition = null)
    {
        return $this->query->delete(null, $lightweight, $partition);
    }

    /**
     * Insert rows using the JSONEachRow input format.
     *
     * Defined explicitly (instead of relying on __call forwarding) so the
     * written-rows count is returned rather than the builder instance.
     *
     * @param  iterable<array<string, mixed>>|array<string, mixed>  $rows
     * @return int The number of written rows reported by ClickHouse.
     */
    public function insertBulk(iterable $rows): int
    {
        /** @var QueryBuilder $builder */
        $builder = $this->toBase();

        return $builder->insertBulk($rows);
    }
}

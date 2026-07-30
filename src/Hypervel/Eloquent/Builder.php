<?php

namespace ClickHouse\Hypervel\Eloquent;

use ClickHouse\Core\Eloquent\DeletesClickHouseModels;
use ClickHouse\Hypervel\Query\Builder as QueryBuilder;
use Hypervel\Database\Eloquent\Builder as BaseBuilder;

/**
 * @template TModel of Model
 *
 * @extends BaseBuilder<TModel>
 *
 * @property QueryBuilder $query
 */
class Builder extends BaseBuilder
{
    use DeletesClickHouseModels;

    /** {@inheritDoc} */
    public function delete(?bool $lightweight = null, mixed $partition = null): mixed
    {
        return $this->deleteClickHouseModels($lightweight, $partition);
    }

    /** {@inheritDoc} */
    public function forceDelete(?bool $lightweight = null, mixed $partition = null): mixed
    {
        return $this->forceDeleteClickHouseModels($lightweight, $partition);
    }
}

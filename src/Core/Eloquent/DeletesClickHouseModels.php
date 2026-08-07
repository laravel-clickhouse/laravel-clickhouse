<?php

namespace ClickHouse\Core\Eloquent;

/**
 * ClickHouse delete behaviour shared by every framework bridge's Eloquent
 * builder. The using class must extend its framework's Eloquent builder,
 * whose $onDelete/$query/toBase() API this trait relies on.
 */
trait DeletesClickHouseModels
{
    /**
     * Delete records honouring the ClickHouse lightweight/partition options.
     *
     * @return mixed
     */
    protected function deleteClickHouseModels(?bool $lightweight, mixed $partition)
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        // @phpstan-ignore-next-line
        return $this->toBase()->delete(null, $lightweight, $partition);
    }

    /**
     * Force-delete records honouring the ClickHouse lightweight/partition options.
     *
     * @return mixed
     */
    protected function forceDeleteClickHouseModels(?bool $lightweight, mixed $partition)
    {
        return $this->query->delete(null, $lightweight, $partition);
    }
}

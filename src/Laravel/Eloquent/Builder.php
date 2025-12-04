<?php

namespace ClickHouse\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * @template TModel of Model
 *
 * @extends BaseBuilder<TModel>
 */
class Builder extends BaseBuilder
{
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
}

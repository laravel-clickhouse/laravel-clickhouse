<?php

namespace ClickHouse\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model as BaseModel;

abstract class Model extends BaseModel
{
    /** {@inheritDoc} */
    public $incrementing = false;

    /**
     * {@inheritDoc}
     *
     * @var class-string<Builder<Model>>
     */
    protected static string $builder = Builder::class;
}

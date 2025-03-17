<?php

namespace ClickHouse\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * @template TModel of Model
 *
 * @extends BaseBuilder<TModel>
 */
class Builder extends BaseBuilder {}

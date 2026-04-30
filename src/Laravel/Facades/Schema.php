<?php

namespace ClickHouse\Laravel\Facades;

use ClickHouse\Laravel\Schema\Builder;
use Illuminate\Support\Facades\Schema as BaseSchema;

/**
 * @method static void dropSync(string $table)
 * @method static void dropIfExistsSync(string $table)
 *
 * @see Builder
 */
class Schema extends BaseSchema {}

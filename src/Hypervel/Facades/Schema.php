<?php

namespace ClickHouse\Hypervel\Facades;

use ClickHouse\Hypervel\Schema\Builder;
use Hypervel\Support\Facades\Schema as BaseSchema;

/**
 * @method static void dropSync(string $table)
 * @method static void dropIfExistsSync(string $table)
 *
 * @see Builder
 */
class Schema extends BaseSchema {}

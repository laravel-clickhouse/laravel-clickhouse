<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\IndexDefinition as BaseIndexDefinition;

/**
 * @method $this granularity(int $value) Specify the granularity for the index
 */
class IndexDefinition extends BaseIndexDefinition {}

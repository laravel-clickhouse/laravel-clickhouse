<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\ColumnDefinition as BaseColumnDefinition;

/**
 * @method $this lowCardinality() Specify that the column has low cardinality
 */
class ColumnDefinition extends BaseColumnDefinition {}

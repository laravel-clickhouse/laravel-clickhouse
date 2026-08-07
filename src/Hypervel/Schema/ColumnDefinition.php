<?php

namespace ClickHouse\Hypervel\Schema;

use ClickHouse\Core\Schema\ClickHouseColumnDefinition;
use Hypervel\Database\Schema\ColumnDefinition as BaseColumnDefinition;

class ColumnDefinition extends BaseColumnDefinition implements ClickHouseColumnDefinition {}

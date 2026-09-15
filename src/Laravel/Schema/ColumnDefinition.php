<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Core\Schema\ClickHouseColumnDefinition;
use Illuminate\Database\Schema\ColumnDefinition as BaseColumnDefinition;

class ColumnDefinition extends BaseColumnDefinition implements ClickHouseColumnDefinition {}

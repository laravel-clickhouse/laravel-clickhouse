<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Core\Schema\ClickHouseIndexDefinition;
use Illuminate\Database\Schema\IndexDefinition as BaseIndexDefinition;

class IndexDefinition extends BaseIndexDefinition implements ClickHouseIndexDefinition {}

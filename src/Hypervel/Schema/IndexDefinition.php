<?php

namespace ClickHouse\Hypervel\Schema;

use ClickHouse\Core\Schema\ClickHouseIndexDefinition;
use Hypervel\Database\Schema\IndexDefinition as BaseIndexDefinition;

class IndexDefinition extends BaseIndexDefinition implements ClickHouseIndexDefinition {}

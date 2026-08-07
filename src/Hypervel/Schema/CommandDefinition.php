<?php

namespace ClickHouse\Hypervel\Schema;

use ClickHouse\Core\Schema\ClickHouseCommandDefinition;
use Hypervel\Support\Fluent;

/**
 * @extends Fluent<string, mixed>
 */
class CommandDefinition extends Fluent implements ClickHouseCommandDefinition {}

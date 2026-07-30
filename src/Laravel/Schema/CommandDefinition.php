<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Core\Schema\ClickHouseCommandDefinition;
use Illuminate\Support\Fluent;

/**
 * @extends Fluent<string, mixed>
 */
class CommandDefinition extends Fluent implements ClickHouseCommandDefinition {}

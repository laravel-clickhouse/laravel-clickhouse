<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Support\Fluent;

/**
 * @method $this sync() Specify that the command should be executed synchronously
 *
 * @extends Fluent<string, mixed>
 */
class CommandDefinition extends Fluent {}

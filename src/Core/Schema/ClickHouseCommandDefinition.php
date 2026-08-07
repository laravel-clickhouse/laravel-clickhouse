<?php

namespace ClickHouse\Core\Schema;

/**
 * Marker contract carrying the ClickHouse command extensions, so each
 * bridge's CommandDefinition documents them once. The methods resolve
 * through Fluent's magic __call at runtime.
 *
 * @method $this sync() Specify that the command should be executed synchronously
 */
interface ClickHouseCommandDefinition {}

<?php

namespace ClickHouse\Core\Schema;

/**
 * Marker contract carrying the ClickHouse index extensions, so each
 * bridge's IndexDefinition documents them once. The methods resolve
 * through Fluent's magic __call at runtime.
 *
 * @method $this granularity(int $value) Specify the granularity for the index
 */
interface ClickHouseIndexDefinition {}

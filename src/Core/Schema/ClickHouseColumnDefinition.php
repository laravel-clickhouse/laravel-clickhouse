<?php

namespace ClickHouse\Core\Schema;

/**
 * Marker contract carrying the ClickHouse column-definition extensions, so
 * each bridge's ColumnDefinition documents them once. The methods resolve
 * through Fluent's magic __call at runtime.
 *
 * @method $this lowCardinality() Specify that the column has low cardinality
 */
interface ClickHouseColumnDefinition {}

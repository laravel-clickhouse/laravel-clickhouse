<?php

namespace ClickHouse\Core\Contracts;

/**
 * Marker contract implemented by every framework-specific ClickHouse
 * connection, so shared code can detect one without referencing any
 * framework class. The actual client API lives in the
 * InteractsWithClickHouseClient trait every implementation uses.
 */
interface ClickHouseConnection {}

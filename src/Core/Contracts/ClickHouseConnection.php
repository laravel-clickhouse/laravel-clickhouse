<?php

namespace ClickHouse\Core\Contracts;

use ClickHouse\Core\Enums\DateTimePrecision;

/**
 * Contract implemented by every framework-specific ClickHouse connection,
 * so shared code can detect one without referencing any framework class.
 * The actual client API lives in the InteractsWithClickHouseClient trait
 * every implementation uses; only what shared code calls through this
 * type is declared here.
 */
interface ClickHouseConnection
{
    public function getDateTimePrecision(): DateTimePrecision;
}

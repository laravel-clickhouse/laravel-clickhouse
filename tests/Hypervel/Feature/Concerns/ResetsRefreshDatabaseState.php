<?php

namespace ClickHouse\Tests\Hypervel\Feature\Concerns;

use Hypervel\Foundation\Testing\RefreshDatabaseState;

/**
 * Forces the class to run its own `migrate:fresh` instead of inheriting the
 * previous class's latched state — RefreshDatabaseState::$migrated is a
 * static flag scoped to the PHP process, and this testbench registers
 * different migration paths per class. See the Laravel counterpart and
 * docs/docs/testing.md "Caveats" for the full explanation.
 */
trait ResetsRefreshDatabaseState
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        RefreshDatabaseState::$migrated = false;
    }
}

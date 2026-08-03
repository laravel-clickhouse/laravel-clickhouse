<?php

namespace ClickHouse\Tests\Feature\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Forces the class to run its own `migrate:fresh` instead of inheriting the
 * previous class's latched state.
 *
 * RefreshDatabaseState::$migrated is a static flag scoped to the PHP process,
 * not to a test class — PHPUnit runs the whole suite in one process, so the
 * first class that sets it leaves it set for every class after.
 *
 * Only scenarios whose schema build is gated on that flag need this reset —
 * DatabaseTruncation and RefreshDatabase run `migrate:fresh` once
 * per process and skip it while the flag is set. This testbench deliberately
 * registers different migration paths per class via `defineDatabaseMigrations()`
 * (ClickHouse-only, SQLite-only, both) to demo each scenario; without the
 * reset, the second gated class skips migrate:fresh and queries tables that
 * were never created. DatabaseMigrations scenarios don't need it: that trait's
 * teardown (`migrate:rollback`) resets the flag itself.
 *
 * Application code with one global migration set never hits this — see
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

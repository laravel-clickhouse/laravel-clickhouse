<?php

namespace ClickHouse\Tests\Hypervel\Feature\RefreshDatabase;

use ClickHouse\Hypervel\Testing\RefreshDatabase;
use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\SqliteOnlyTestCase;
use Hypervel\Support\Facades\DB;

/**
 * Regression guard: the package's RefreshDatabase wrapper preserves the
 * framework behaviour on a pure-SQLite class — bare `:memory:` schema built
 * once, each test's writes rolled back. The wrapper's pre-wipe still runs
 * (its derived target here is just the sqlite default connection), so this
 * pins that the extension adds nothing destructive on top.
 */
class SqliteOnlyTest extends SqliteOnlyTestCase
{
    use RefreshDatabase;
    use ResetsRefreshDatabaseState;

    public function testRound1Inserts(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
    }

    public function testRound2SeesRolledBackTable(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
    }
}

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Testing\RefreshDatabase;

use ClickHouse\Tests\Hypervel\Feature\Testing\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\Testing\SqliteOnlyTestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;

/**
 * RefreshDatabase preserves the in-memory SQLite schema and rolls back each
 * test's writes.
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

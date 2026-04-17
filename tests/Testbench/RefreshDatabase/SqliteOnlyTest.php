<?php

namespace ClickHouse\Tests\Testbench\RefreshDatabase;

use ClickHouse\Tests\Testbench\SqliteOnlyTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Regression guard: with ClickHouseServiceProvider enabled and our Connection
 * transaction override in place, a pure-SQLite connection still rolls back
 * exactly like vanilla Laravel under RefreshDatabase.
 */
class SqliteOnlyTest extends SqliteOnlyTestCase
{
    use RefreshDatabase;

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

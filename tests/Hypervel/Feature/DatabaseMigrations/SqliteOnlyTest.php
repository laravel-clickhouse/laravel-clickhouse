<?php

namespace ClickHouse\Tests\Hypervel\Feature\DatabaseMigrations;

use ClickHouse\Tests\Hypervel\Feature\SqliteOnlyTestCase;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;

/**
 * Regression guard: ClickHouseServiceProvider::register() rebinds
 * migration.repository as an app-wide singleton, but that repository writes
 * each migration against the connection the migration itself declares. A
 * pure-SQLite scenario therefore sees migrate:fresh / migrate:rollback behave
 * exactly like vanilla Hypervel.
 */
class SqliteOnlyTest extends SqliteOnlyTestCase
{
    use DatabaseMigrations;

    public function testRound1Inserts(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
    }

    public function testRound2SeesFreshTable(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
    }
}

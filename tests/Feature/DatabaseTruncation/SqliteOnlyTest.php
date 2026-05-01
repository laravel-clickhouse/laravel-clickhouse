<?php

namespace ClickHouse\Tests\Feature\DatabaseTruncation;

use ClickHouse\Tests\Feature\SqliteOnlyTestCase;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * Regression guard: a pure-SQLite connection under DatabaseTruncation still
 * has its tables wiped between tests, exactly like vanilla Laravel.
 */
class SqliteOnlyTest extends SqliteOnlyTestCase
{
    use DatabaseTruncation;

    public function testRound1Inserts(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
    }

    public function testRound2SeesTruncatedTable(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
    }
}

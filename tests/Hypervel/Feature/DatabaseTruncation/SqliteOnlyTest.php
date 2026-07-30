<?php

namespace ClickHouse\Tests\Hypervel\Feature\DatabaseTruncation;

use ClickHouse\Tests\Hypervel\Feature\Concerns\ResetsRefreshDatabaseState;
use ClickHouse\Tests\Hypervel\Feature\Concerns\UsesSharedSqliteDatabase;
use ClickHouse\Tests\Hypervel\Feature\SqliteOnlyTestCase;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;

/**
 * Regression guard: a pure-SQLite connection under DatabaseTruncation still
 * has its tables wiped between tests, exactly like vanilla Hypervel.
 */
class SqliteOnlyTest extends SqliteOnlyTestCase
{
    use DatabaseTruncation;
    use ResetsRefreshDatabaseState;
    use UsesSharedSqliteDatabase;

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

<?php

namespace ClickHouse\Tests\Testbench\DatabaseMigrations;

use ClickHouse\Tests\Testbench\SqliteWithClickHouseTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/**
 * Combined-scenario DatabaseMigrations: a single migration.repository handles
 * migrations from both drivers. Each migration writes its schema against the
 * connection it declares, so SQLite migrations land on SQLite and ClickHouse
 * migrations land on ClickHouse, with no cross-talk.
 */
class SqliteWithClickHouseTest extends SqliteWithClickHouseTestCase
{
    use DatabaseMigrations;

    public function testRound1InsertsIntoBothConnections(): void
    {
        DB::connection('sqlite')->table('sq_users')->insert(['id' => 1, 'name' => 'sqlite-a']);
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'ch-a']);

        $this->assertSame(1, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testRound2BothTablesAreFresh(): void
    {
        $this->assertSame(0, DB::connection('sqlite')->table('sq_users')->count());
        $this->assertSame(0, DB::connection('clickhouse')->table('ch_events')->count());
    }
}

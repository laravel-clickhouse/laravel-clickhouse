<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;

class ConnectionTest extends TestCase
{
    public function testResolvesClickHouseConnectionFromManager()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('clickhouse', $connection->getName());
        $this->assertSame('clickhouse', $connection->getDriverName());
        $this->assertSame('default', $connection->getDatabaseName());
    }

    public function testSelect()
    {
        $rows = $this->app->make('db')->connection('clickhouse')->select('SELECT 1 AS one');

        $this->assertSame([['one' => 1]], $rows);
    }

    public function testSelectWithBindings()
    {
        $rows = $this->app->make('db')->connection('clickhouse')->select('SELECT ? AS value', ['clickhouse']);

        $this->assertSame([['value' => 'clickhouse']], $rows);
    }

    public function testSessionKeepsTemporaryTablesAcrossQueries()
    {
        $words = DB::connection('clickhouse')->session(function ($connection) {
            $connection->statement('CREATE TEMPORARY TABLE session_words (word String) ENGINE = Memory');
            $connection->table('session_words')->insert(['word' => 'clickhouse']);

            return $connection->table('session_words')->pluck('word')->all();
        });

        $this->assertSame(['clickhouse'], $words);
    }

    public function testQueriesResolvedThroughTheFacadeJoinTheSession()
    {
        $words = DB::connection('clickhouse')->session(function ($connection) {
            $connection->statement('CREATE TEMPORARY TABLE session_words (word String) ENGINE = Memory');

            // Resolved again through the facade rather than the callback
            // argument — the same connection, so still inside the session.
            DB::connection('clickhouse')->table('session_words')->insert(['word' => 'facade']);

            return DB::connection('clickhouse')->table('session_words')->pluck('word')->all();
        });

        $this->assertSame(['facade'], $words);
    }

    public function testTemporaryTablesDoNotOutliveTheSession()
    {
        DB::connection('clickhouse')->session(function ($connection) {
            $connection->statement('CREATE TEMPORARY TABLE session_words (word String) ENGINE = Memory');
        });

        $this->assertNull(DB::connection('clickhouse')->getSession());

        $this->expectException(QueryException::class);

        DB::connection('clickhouse')->table('session_words')->count();
    }
}

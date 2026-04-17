<?php

namespace ClickHouse\Tests\Testbench\RefreshDatabase;

use ClickHouse\Tests\Testbench\ClickHouseOnlyTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * ClickHouse has no real transaction support, so this package overrides
 * Connection::beginTransaction / commit / rollBack / transaction to be no-ops.
 * These tests verify that RefreshDatabase no longer crashes from the missing
 * PDO and that transactionLevel still tracks correctly.
 *
 * Caveat: RefreshDatabase relies on transaction rollback for isolation, and
 * since ClickHouse transactions are no-ops, data is NOT rolled back between
 * tests. Use DatabaseTruncation or DatabaseMigrations for real isolation.
 */
class ClickHouseOnlyTest extends ClickHouseOnlyTestCase
{
    use RefreshDatabase;

    public function testInsertDoesNotCrashUnderRefreshDatabase(): void
    {
        DB::connection('clickhouse')->table('ch_events')->insert(['id' => 1, 'name' => 'a']);

        $this->assertSame(1, DB::connection('clickhouse')->table('ch_events')->count());
    }

    public function testTransactionLevelTracksWithoutPdo(): void
    {
        $conn = DB::connection('clickhouse');
        // RefreshDatabase has already begun a transaction, so compare deltas.
        $baseline = $conn->transactionLevel();

        $conn->beginTransaction();
        $this->assertSame($baseline + 1, $conn->transactionLevel());

        $conn->beginTransaction();
        $this->assertSame($baseline + 2, $conn->transactionLevel());

        $conn->commit();
        $this->assertSame($baseline + 1, $conn->transactionLevel());

        $conn->rollBack();
        $this->assertSame($baseline, $conn->transactionLevel());
    }

    public function testTransactionClosureReturnsValue(): void
    {
        $conn = DB::connection('clickhouse');
        $baseline = $conn->transactionLevel();

        $result = $conn->transaction(fn () => 42);

        $this->assertSame(42, $result);
        $this->assertSame($baseline, $conn->transactionLevel());
    }
}

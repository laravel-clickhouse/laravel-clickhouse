<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Core\Enums\Format;
use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Hypervel\Query\Builder;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use DateTimeImmutable;
use Hypervel\Database\QueryException;

class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('query_test');
        Schema::create('query_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->array('tags', 'String');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('query_test');

        parent::tearDown();
    }

    public function testInsertAndSelect()
    {
        $inserted = $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => ['a', 'b']],
            ['id' => 2, 'name' => 'second', 'tags' => ['b']],
        ]);

        $this->assertTrue($inserted);
        $this->assertSame(2, $this->table()->count());
    }

    public function testPrewhere()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => []],
            ['id' => 2, 'name' => 'second', 'tags' => []],
        ]);

        $rows = $this->table()->prewhere('id', '>', 1)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('second', $rows[0]['name']);
    }

    public function testArrayJoin()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => ['a', 'b']],
        ]);

        $rows = $this->table()->select('tag')->arrayJoin(['tag' => 'tags'])->orderBy('tag')->get();

        $this->assertSame(['a', 'b'], $rows->pluck('tag')->all());
    }

    public function testLimitBy()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'duplicated', 'tags' => []],
            ['id' => 2, 'name' => 'duplicated', 'tags' => []],
            ['id' => 3, 'name' => 'unique', 'tags' => []],
        ]);

        $rows = $this->table()->limitBy(1, 'name')->orderBy('id')->get();

        $this->assertCount(2, $rows);
    }

    public function testDelete()
    {
        $this->table()->insert([
            ['id' => 1, 'name' => 'first', 'tags' => []],
            ['id' => 2, 'name' => 'second', 'tags' => []],
        ]);

        $this->table()->where('id', 1)->delete(lightweight: true);

        $this->assertSame(1, $this->table()->count());
    }

    public function testInsertWithFormat()
    {
        $connection = $this->app->make('db')->connection('clickhouse_micro');

        $connection->statement('create table query_format_test (id UInt64, name String, tags Array(String), created_at DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('query_format_test')->insert([
                ['id' => 1, 'name' => 'héllo 👋', 'tags' => ['a', 'b'], 'created_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456')],
                ['id' => 2, 'name' => 'second', 'tags' => [], 'created_at' => new DateTimeImmutable('2026-07-29 12:34:56.654321')],
            ], format: Format::JSONEachRow);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [
                    ['id' => 1, 'name' => 'héllo 👋', 'tags' => ['a', 'b'], 'created_at' => '2026-07-29 12:34:56.123456'],
                    ['id' => 2, 'name' => 'second', 'tags' => [], 'created_at' => '2026-07-29 12:34:56.654321'],
                ],
                $connection->table('query_format_test')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table query_format_test');
        }
    }

    /**
     * At the default second precision, DateTimeInterface bindings are
     * truncated before they reach the SQL — matching Laravel's behavior on
     * other databases: every context works on every ClickHouse version, at
     * the cost of sub-second exactness ('datetime_precision' =>
     * 'microsecond' opts into exact comparisons).
     */
    public function testDateTimeBindingsTruncateAtDefaultSecondPrecision()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $connection->statement('create table query_datetime_default_test (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $connection->table('query_datetime_default_test')->insert([
                ['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456'],
            ], format: Format::JSONEachRow);

            $table = fn () => $connection->table('query_datetime_default_test');

            $this->assertEquals(
                [1],
                $table()->where('dt', '=', new DateTimeImmutable('2026-08-13 10:00:00.123456'))->pluck('id')->all()
            );

            $this->assertEquals(
                [1],
                $table()->whereIn('dt', [new DateTimeImmutable('2026-08-13 10:00:00.123456')])->pluck('id')->all()
            );

            // The documented cost of the safe default: a sub-second DateTime64
            // value read from a row no longer matches it once truncated.
            $this->assertEquals(
                [],
                $table()->where('dt64', '=', new DateTimeImmutable('2026-08-13 10:00:00.123456'))->pluck('id')->all()
            );
        } finally {
            $connection->statement('drop table query_datetime_default_test');
        }
    }

    /**
     * Regression test for issue #29, at 'datetime_precision' =>
     * 'microsecond': a DateTimeInterface binding carrying microseconds must
     * not error against a second-precision DateTime column (older ClickHouse
     * versions reject the bare fractional literal) and must not be truncated
     * before the comparison (newer versions would otherwise wrongly match on
     * equality).
     */
    public function testDateTimeBindingsCompareExactlyAtMicrosecondPrecision()
    {
        $connection = $this->app->make('db')->connection('clickhouse_micro');

        $connection->statement('create table query_datetime_test (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $connection->table('query_datetime_test')->insert([
                ['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456'],
                ['id' => 2, 'dt' => '2026-08-13 11:00:00', 'dt64' => '2026-08-13 11:00:00.500000'],
            ], format: Format::JSONEachRow);

            $table = fn () => $connection->table('query_datetime_test');

            $this->assertEquals(
                [2],
                $table()->where('dt', '>', new DateTimeImmutable('2026-08-13 10:00:00.000001'))->pluck('id')->all()
            );

            $this->assertEquals(
                [],
                $table()->where('dt', '=', new DateTimeImmutable('2026-08-13 10:00:00.123456'))->pluck('id')->all()
            );

            $this->assertEquals(
                [1],
                $table()->where('dt', '=', new DateTimeImmutable('2026-08-13 10:00:00'))->pluck('id')->all()
            );

            $this->assertEquals(
                [1],
                $table()->whereBetween('dt64', [
                    new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                    new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                ])->pluck('id')->all()
            );

            // Whole-second IN elements are plain literals and work against
            // both column types on every ClickHouse version.
            $this->assertEquals(
                [1],
                $table()->whereIn('dt', [
                    new DateTimeImmutable('2026-08-13 10:00:00'),
                    new DateTimeImmutable('2026-08-13 09:00:00'),
                ])->pluck('id')->all()
            );

            // Sub-second IN lookups round-trip exactly against DateTime64.
            $this->assertEquals(
                [1],
                $table()->whereIn('dt64', [new DateTimeImmutable('2026-08-13 10:00:00.123456')])->pluck('id')->all()
            );

            // ClickHouse's IN-section type check rejects DateTime64 set
            // elements against a second-precision DateTime column on every
            // supported version, so a sub-second whereIn() fails loudly
            // instead of silently matching a truncated value. If a future
            // version starts accepting this, revisit the documented
            // limitation in docs/docs/eloquent.md.
            try {
                $table()->whereIn('dt', [new DateTimeImmutable('2026-08-13 10:00:00.123456')])->get();

                $this->fail('Expected a QueryException for a sub-second IN element against a DateTime column.');
            } catch (QueryException) {
            }
        } finally {
            $connection->statement('drop table query_datetime_test');
        }
    }

    /**
     * Values-format inserts normalize DateTimeInterface values to second
     * precision (BuildsClickHouseQueries::formatInsertDateTimes()):
     * ClickHouse 25.8 and older reject sub-second content in the VALUES
     * section when the target is a second-precision DateTime column, so a
     * microsecond Carbon must insert cleanly into a DateTime column on every
     * supported version. Sub-second inserts into DateTime64 columns opt in
     * via 'datetime_precision' => 'microsecond' or pre-formatted strings.
     */
    public function testInsertValuesFormatTruncatesDateTimeObjects()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $connection->statement('create table query_values_datetime_test (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('query_values_datetime_test')->insert([
                'id' => 1,
                'dt' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                'dt64' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
            ]);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.000000']],
                $connection->table('query_values_datetime_test')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table query_values_datetime_test');
        }
    }

    /**
     * JSONEachRow is a transport choice, not a precision one, so its
     * DateTimeInterface objects follow datetime_precision exactly like the
     * Values path: at the default second precision a microsecond Carbon
     * must insert cleanly into a DateTime column, which ClickHouse 25.8 and
     * older would reject as a fractional JSON string. A pre-formatted string
     * still carries its own precision into the DateTime64 column.
     */
    public function testInsertJsonEachRowTruncatesDateTimeObjects()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $connection->statement('create table query_json_datetime_test (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('query_json_datetime_test')->insert([
                [
                    'id' => 1,
                    'dt' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                    'dt64' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                ],
                [
                    'id' => 2,
                    'dt' => new DateTimeImmutable('2026-08-13 11:00:00'),
                    'dt64' => (new DateTimeImmutable('2026-08-13 11:00:00.123456'))->format('Y-m-d H:i:s.u'),
                ],
            ], format: Format::JSONEachRow);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [
                    ['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.000000'],
                    ['id' => 2, 'dt' => '2026-08-13 11:00:00', 'dt64' => '2026-08-13 11:00:00.123456'],
                ],
                $connection->table('query_json_datetime_test')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table query_json_datetime_test');
        }
    }

    /**
     * At 'datetime_precision' => 'microsecond', Values-format inserts render
     * microsecond strings, which every ClickHouse version accepts for
     * DateTime64 columns.
     */
    public function testInsertValuesFormatKeepsMicrosecondsAtMicrosecondPrecision()
    {
        $connection = $this->app->make('db')->connection('clickhouse_micro');

        $connection->statement('create table query_values_micro_test (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('query_values_micro_test')->insert([
                'id' => 1,
                'dt' => new DateTimeImmutable('2026-08-13 10:00:00'),
                'dt64' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
            ]);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456']],
                $connection->table('query_values_micro_test')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table query_values_micro_test');
        }
    }

    protected function table(): Builder
    {
        return $this->app->make('db')->connection('clickhouse')->table('query_test');
    }
}

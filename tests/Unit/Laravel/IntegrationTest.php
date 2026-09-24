<?php

namespace ClickHouse\Tests\Unit\Laravel;

use ClickHouse\Enums\Format;
use ClickHouse\Laravel\Connection;
use ClickHouse\Laravel\Eloquent\Model as BaseClickHouseModel;
use ClickHouse\Laravel\Parallel;
use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;
use ClickHouse\Tests\Unit\TestCase;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model as BaseSQLiteModel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;

class IntegrationTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloquent();
        $this->createClickHouseTestTable();
    }

    protected function tearDown(): void
    {
        $this->dropClickHouseTestTable();

        parent::tearDown();
    }

    public function testCreate()
    {
        ClickHouseModel::create(['id' => 1, 'column' => 'value']);

        $this->assertEquals(
            [['id' => 1, 'column' => 'value']],
            ClickHouseModel::all()->toArray()
        );
    }

    public function testUpdate()
    {
        ClickHouseModel::create(['id' => 1, 'column' => 'value']);
        ClickHouseModel::where('id', 1)->update(['column' => 'new_value']);

        $this->assertEquals(
            [['id' => 1, 'column' => 'new_value']],
            ClickHouseModel::all()->toArray()
        );
    }

    public function testDelete()
    {
        ClickHouseModel::create(['id' => 1, 'column' => 'value']);
        ClickHouseModel::where('id', 1)->delete();

        $this->assertEquals(
            [],
            ClickHouseModel::all()->toArray()
        );
    }

    public function testArrayJoin()
    {
        ClickHouseModel::create(['id' => 1, 'column' => 'value']);

        $this->assertEquals(
            [
                ['id' => 1, 'column' => 'value', 'alias' => 'foo'],
                ['id' => 1, 'column' => 'value', 'alias' => 'bar'],
            ],
            ClickHouseModel::query()
                ->arrayJoin(ClickHouseModel::selectRaw("['foo', 'bar']"), 'alias')
                ->get()
                ->toArray()
        );
    }

    public function testRelation()
    {
        $model = ClickHouseModel::create(['id' => 1, 'column' => 'value']);

        $this->assertTrue($model->related->is($model));
    }

    public function testRelationWithSQLite()
    {
        $this->addSQLiteConnection();

        $this->createSQLiteTestTable();

        $clickhouseModel = ClickHouseModel::create(['id' => 1, 'column' => 'value']);
        $sqliteModel = SQLiteModel::create(['id' => 1, 'column' => 'another_value']);

        $this->assertTrue($clickhouseModel->sqliteRelated->is($sqliteModel));

        $this->dropSQLiteTestTable();
    }

    public function testInsertWithFormat()
    {
        $inserted = $this->db->getConnection('clickhouse')->table('test')->insert([
            ['id' => 1, 'column' => 'value_1'],
            ['id' => 2, 'column' => 'héllo 👋'],
        ], format: Format::JSONEachRow);

        $this->assertTrue($inserted);
        $this->assertEquals(
            [
                ['id' => 1, 'column' => 'value_1'],
                ['id' => 2, 'column' => 'héllo 👋'],
            ],
            ClickHouseModel::orderBy('id')->get()->toArray()
        );
    }

    public function testInsertWithFormatAndTypedColumns()
    {
        $connection = $this->db->getConnection('clickhouse');

        $connection->statement('create table test_format_types (tags Array(String), created_at DateTime64(6), id UInt64) engine = Memory');

        try {
            $inserted = $connection->table('test_format_types')->insert([
                'tags' => ['a', 'b'],
                'created_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456'),
                'id' => 1,
            ], format: Format::JSONEachRow);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [['tags' => ['a', 'b'], 'created_at' => '2026-07-29 12:34:56.123456', 'id' => 1]],
                $connection->table('test_format_types')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table test_format_types');
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
        $connection = $this->db->getConnection('clickhouse');

        $connection->statement('create table test_datetime_default (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $connection->table('test_datetime_default')->insert([
                ['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456'],
            ], format: Format::JSONEachRow);

            $table = fn () => $connection->table('test_datetime_default');

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
            $connection->statement('drop table test_datetime_default');
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
        $connection = $this->db->getConnection('clickhouse_micro');

        $connection->statement('create table test_datetime_bindings (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $connection->table('test_datetime_bindings')->insert([
                ['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456'],
                ['id' => 2, 'dt' => '2026-08-13 11:00:00', 'dt64' => '2026-08-13 11:00:00.500000'],
            ], format: Format::JSONEachRow);

            $table = fn () => $connection->table('test_datetime_bindings');

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
            $connection->statement('drop table test_datetime_bindings');
        }
    }

    /**
     * Values-format inserts normalize DateTimeInterface values to second
     * precision (Query\Builder::formatInsertDateTimes()): ClickHouse 25.8
     * and older reject sub-second content in the VALUES section when the
     * target is a second-precision DateTime column, so a microsecond Carbon
     * must insert cleanly into a DateTime column on every supported version.
     * Sub-second inserts into DateTime64 columns use Format::JSONEachRow.
     */
    public function testInsertValuesFormatTruncatesDateTimeObjects()
    {
        $connection = $this->db->getConnection('clickhouse');

        $connection->statement('create table test_values_datetime (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('test_values_datetime')->insert([
                'id' => 1,
                'dt' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
                'dt64' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
            ]);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.000000']],
                $connection->table('test_values_datetime')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table test_values_datetime');
        }
    }

    /**
     * At 'datetime_precision' => 'microsecond', Values-format inserts render
     * microsecond strings, which every ClickHouse version accepts for
     * DateTime64 columns.
     */
    public function testInsertValuesFormatKeepsMicrosecondsAtMicrosecondPrecision()
    {
        $connection = $this->db->getConnection('clickhouse_micro');

        $connection->statement('create table test_values_micro (id UInt64, dt DateTime, dt64 DateTime64(6)) engine = Memory');

        try {
            $inserted = $connection->table('test_values_micro')->insert([
                'id' => 1,
                'dt' => new DateTimeImmutable('2026-08-13 10:00:00'),
                'dt64' => new DateTimeImmutable('2026-08-13 10:00:00.123456'),
            ]);

            $this->assertTrue($inserted);
            $this->assertEquals(
                [['id' => 1, 'dt' => '2026-08-13 10:00:00', 'dt64' => '2026-08-13 10:00:00.123456']],
                $connection->table('test_values_micro')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table test_values_micro');
        }
    }

    /**
     * Date attributes default to the Laravel-wide second-precision storage
     * format, which every ClickHouse version accepts for DateTime columns;
     * models persisting into DateTime64 columns opt into sub-second
     * precision by setting $dateFormat explicitly.
     */
    public function testModelDateAttributesDefaultToSecondPrecisionWithOptIn()
    {
        $connection = $this->db->getConnection('clickhouse');

        $connection->statement('create table test_date_format (id UInt64, occurred_at DateTime64(6)) engine = Memory');

        try {
            DefaultDateFormatModel::create(['id' => 1, 'occurred_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456')]);
            MicrosecondDateFormatModel::create(['id' => 2, 'occurred_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456')]);

            $this->assertEquals(
                [
                    ['id' => 1, 'occurred_at' => '2026-07-29 12:34:56.000000'],
                    ['id' => 2, 'occurred_at' => '2026-07-29 12:34:56.123456'],
                ],
                $connection->table('test_date_format')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table test_date_format');
        }
    }

    public function testInsertWithFormatThroughModel()
    {
        $inserted = ClickHouseModel::insert([
            ['id' => 1, 'column' => 'value'],
        ], format: Format::JSONEachRow);

        $this->assertTrue($inserted);
        $this->assertEquals(
            [['id' => 1, 'column' => 'value']],
            ClickHouseModel::all()->toArray()
        );
    }

    public function testGetParallelly()
    {
        ClickHouseModel::create(['id' => 1, 'column' => 'value']);
        ClickHouseModel::create(['id' => 2, 'column' => 'value']);
        ClickHouseModel::create(['id' => 3, 'column' => 'value']);

        $results = Parallel::get([
            'one' => ClickHouseModel::where('id', 1),
            'two' => ClickHouseModel::where('id', 2)->toBase(),
            'three' => ClickHouseModel::where('id', 3),
        ]);

        $this->assertInstanceOf(EloquentCollection::class, $results['one']);
        $this->assertInstanceOf(Collection::class, $results['two']);
        $this->assertInstanceOf(EloquentCollection::class, $results['three']);
        $this->assertInstanceOf(ClickHouseModel::class, $results['one']->first());
        $this->assertIsArray($results['two']->first());
        $this->assertInstanceOf(ClickHouseModel::class, $results['three']->first());
        $this->assertEquals(
            [
                'one' => [['id' => 1, 'column' => 'value']],
                'two' => [['id' => 2, 'column' => 'value']],
                'three' => [['id' => 3, 'column' => 'value']],
            ],
            collect($results)->toArray()
        );
    }

    private function setUpEloquent()
    {
        $this->db = new DB;

        $this->db->getDatabaseManager()->extend('clickhouse', function ($config, $name) {
            return new Connection(
                database: $config['database'] ?? '',
                config: array_merge($config, compact('name'))
            );
        });

        $this->addClickHouseConnection();

        $this->db->bootEloquent();
    }

    private function addClickHouseConnection()
    {
        $config = [
            'driver' => 'clickhouse',
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => getenv('CLICKHOUSE_PORT'),
            'database' => getenv('CLICKHOUSE_DATABASE'),
            'username' => getenv('CLICKHOUSE_USERNAME'),
            'password' => getenv('CLICKHOUSE_PASSWORD'),
        ];

        $this->db->addConnection($config, 'clickhouse');
        $this->db->addConnection($config + ['datetime_precision' => 'microsecond'], 'clickhouse_micro');
    }

    private function createClickHouseTestTable()
    {
        $schema = $this->db->getConnection('clickhouse')->getSchemaBuilder();
        $schema->create('test', function (ClickHouseBlueprint $table) {
            $table->unsignedInteger('id');
            $table->text('column');
            $table->engine('Memory');
        });
    }

    private function dropClickHouseTestTable()
    {
        $schema = $this->db->getConnection('clickhouse')->getSchemaBuilder();
        $schema->drop('test');
    }

    private function addSQLiteConnection()
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'sqlite');
    }

    private function createSQLiteTestTable()
    {
        $schema = $this->db->getConnection('sqlite')->getSchemaBuilder();
        $schema->create('test', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->text('column');
        });
    }

    private function dropSQLiteTestTable()
    {
        $schema = $this->db->getConnection('sqlite')->getSchemaBuilder();
        $schema->drop('test');
    }
}

class ClickHouseModel extends BaseClickHouseModel
{
    public $timestamps = false;

    protected $connection = 'clickhouse';

    protected $table = 'test';

    protected $fillable = ['id', 'column'];

    public function related()
    {
        return $this->belongsTo(static::class, 'id', 'id');
    }

    public function sqliteRelated()
    {
        return $this->belongsTo(SQLiteModel::class, 'id', 'id');
    }
}

class DefaultDateFormatModel extends BaseClickHouseModel
{
    public $timestamps = false;

    protected $connection = 'clickhouse';

    protected $table = 'test_date_format';

    protected $fillable = ['id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];
}

class MicrosecondDateFormatModel extends DefaultDateFormatModel
{
    protected $dateFormat = 'Y-m-d H:i:s.u';
}

class SQLiteModel extends BaseSQLiteModel
{
    public $timestamps = false;

    protected $connection = 'sqlite';

    protected $table = 'test';

    protected $fillable = ['id', 'column'];
}

<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Hypervel\Eloquent\Builder;
use ClickHouse\Hypervel\Eloquent\Model;
use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Tests\Hypervel\Feature\TestCase;
use DateTimeImmutable;
use Hypervel\Database\Eloquent\Model as BaseSQLiteModel;
use Hypervel\Database\Schema\Blueprint;
use UnitEnum;

class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Memory engine keeps ALTER TABLE mutations (update / delete)
        // synchronous, so their effect is immediately assertable; the
        // MergeTree-only lightweight delete is covered by QueryTest.
        Schema::dropIfExistsSync('model_test');
        Schema::create('model_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->engine('Memory');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('model_test');

        parent::tearDown();
    }

    public function testQueryReturnsClickHouseBuilder()
    {
        $this->assertInstanceOf(Builder::class, ClickHouseModel::query());
    }

    public function testCreateAndFind()
    {
        ClickHouseModel::create(['id' => 1, 'name' => 'first']);

        $model = ClickHouseModel::query()->find(1);

        $this->assertNotNull($model);
        $this->assertSame('first', $model->name);
    }

    public function testUpdate()
    {
        ClickHouseModel::create(['id' => 1, 'name' => 'first']);
        ClickHouseModel::query()->where('id', 1)->update(['name' => 'renamed']);

        $this->assertSame('renamed', ClickHouseModel::query()->find(1)->name);
    }

    public function testDelete()
    {
        ClickHouseModel::create(['id' => 1, 'name' => 'first']);
        ClickHouseModel::create(['id' => 2, 'name' => 'second']);

        ClickHouseModel::query()->where('id', 1)->delete();

        $this->assertSame(1, ClickHouseModel::query()->count());
    }

    public function testRelation()
    {
        $model = ClickHouseModel::create(['id' => 1, 'name' => 'first']);

        $this->assertTrue($model->related->is($model));
    }

    public function testRelationWithSQLite()
    {
        $sqliteSchema = $this->app->make('db')->connection('sqlite')->getSchemaBuilder();

        $sqliteSchema->create('model_sqlite_test', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->text('name');
        });

        $clickhouseModel = ClickHouseModel::create(['id' => 1, 'name' => 'first']);
        $sqliteModel = SQLiteModel::create(['id' => 1, 'name' => 'another']);

        $this->assertTrue($clickhouseModel->sqliteRelated->is($sqliteModel));

        $sqliteSchema->drop('model_sqlite_test');
    }

    /**
     * Date attributes default to the framework-wide second-precision storage
     * format, which every ClickHouse version accepts for DateTime columns;
     * models persisting into DateTime64 columns opt into sub-second
     * precision by setting $dateFormat explicitly.
     */
    public function testDateAttributesDefaultToSecondPrecisionWithOptIn()
    {
        $connection = $this->app->make('db')->connection('clickhouse');

        $connection->statement('create table model_date_format_test (id UInt64, occurred_at DateTime64(6)) engine = Memory');

        try {
            DefaultDateFormatModel::create(['id' => 1, 'occurred_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456')]);
            MicrosecondDateFormatModel::create(['id' => 2, 'occurred_at' => new DateTimeImmutable('2026-07-29 12:34:56.123456')]);

            $this->assertEquals(
                [
                    ['id' => 1, 'occurred_at' => '2026-07-29 12:34:56.000000'],
                    ['id' => 2, 'occurred_at' => '2026-07-29 12:34:56.123456'],
                ],
                $connection->table('model_date_format_test')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
            );
        } finally {
            $connection->statement('drop table model_date_format_test');
        }
    }
}

class ClickHouseModel extends Model
{
    public bool $timestamps = false;

    protected UnitEnum|string|null $connection = 'clickhouse';

    protected ?string $table = 'model_test';

    protected array $fillable = ['id', 'name'];

    public function related()
    {
        return $this->belongsTo(static::class, 'id', 'id');
    }

    public function sqliteRelated()
    {
        return $this->belongsTo(SQLiteModel::class, 'id', 'id');
    }
}

class SQLiteModel extends BaseSQLiteModel
{
    public bool $timestamps = false;

    protected UnitEnum|string|null $connection = 'sqlite';

    protected ?string $table = 'model_sqlite_test';

    protected array $fillable = ['id', 'name'];
}

class DefaultDateFormatModel extends Model
{
    public bool $timestamps = false;

    protected UnitEnum|string|null $connection = 'clickhouse';

    protected ?string $table = 'model_date_format_test';

    protected array $fillable = ['id', 'occurred_at'];

    protected array $casts = ['occurred_at' => 'datetime'];
}

class MicrosecondDateFormatModel extends DefaultDateFormatModel
{
    protected ?string $dateFormat = 'Y-m-d H:i:s.u';
}

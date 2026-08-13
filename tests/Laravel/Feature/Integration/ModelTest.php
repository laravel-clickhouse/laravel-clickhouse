<?php

namespace ClickHouse\Tests\Laravel\Feature\Integration;

use ClickHouse\Laravel\Connection;
use ClickHouse\Laravel\Eloquent\Builder;
use ClickHouse\Laravel\Eloquent\Model as BaseClickHouseModel;
use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;
use ClickHouse\Tests\Laravel\Unit\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as BaseSQLiteModel;
use Illuminate\Database\Schema\Blueprint;

class ModelTest extends TestCase
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
        $this->addSQLiteConnection();

        $this->createSQLiteTestTable();

        $clickhouseModel = ClickHouseModel::create(['id' => 1, 'name' => 'first']);
        $sqliteModel = SQLiteModel::create(['id' => 1, 'name' => 'another']);

        $this->assertTrue($clickhouseModel->sqliteRelated->is($sqliteModel));

        $this->dropSQLiteTestTable();
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
        $this->db->addConnection([
            'driver' => 'clickhouse',
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => getenv('CLICKHOUSE_PORT'),
            'database' => getenv('CLICKHOUSE_DATABASE'),
            'username' => getenv('CLICKHOUSE_USERNAME'),
            'password' => getenv('CLICKHOUSE_PASSWORD'),
        ], 'clickhouse');
    }

    private function createClickHouseTestTable()
    {
        $schema = $this->db->getConnection('clickhouse')->getSchemaBuilder();
        // Memory engine keeps ALTER TABLE mutations (update / delete)
        // synchronous, so their effect is immediately assertable; the
        // MergeTree-only lightweight delete is covered by QueryTest.
        $schema->create('model_test', function (ClickHouseBlueprint $table) {
            $table->unsignedInteger('id');
            $table->text('name');
            $table->engine('Memory');
        });
    }

    private function dropClickHouseTestTable()
    {
        $schema = $this->db->getConnection('clickhouse')->getSchemaBuilder();
        $schema->drop('model_test');
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
        $schema->create('model_sqlite_test', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->text('name');
        });
    }

    private function dropSQLiteTestTable()
    {
        $schema = $this->db->getConnection('sqlite')->getSchemaBuilder();
        $schema->drop('model_sqlite_test');
    }
}

class ClickHouseModel extends BaseClickHouseModel
{
    public $timestamps = false;

    protected $connection = 'clickhouse';

    protected $table = 'model_test';

    protected $fillable = ['id', 'name'];

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
    public $timestamps = false;

    protected $connection = 'sqlite';

    protected $table = 'model_sqlite_test';

    protected $fillable = ['id', 'name'];
}

<?php

namespace ClickHouse\Tests\Laravel;

use ClickHouse\Laravel\Connection;
use ClickHouse\Laravel\Eloquent\Model as BaseClickHouseModel;
use ClickHouse\Laravel\Parallel;
use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model as BaseSQLiteModel;
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

class SQLiteModel extends BaseSQLiteModel
{
    public $timestamps = false;

    protected $connection = 'sqlite';

    protected $table = 'test';

    protected $fillable = ['id', 'column'];
}

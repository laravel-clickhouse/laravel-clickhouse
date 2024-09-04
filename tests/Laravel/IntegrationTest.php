<?php

namespace SwooleTW\ClickHouse\Tests\Laravel;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as BaseSQLiteModel;
use SwooleTW\ClickHouse\Laravel\Connection;
use SwooleTW\ClickHouse\Laravel\Eloquent\Model as BaseClickHouseModel;
use SwooleTW\ClickHouse\Tests\TestCase;

class IntegrationTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloquent();
        $this->createTestTable();
    }

    protected function tearDown(): void
    {
        $this->truncateTestTable();

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

    public function testRelation()
    {
        $model = ClickHouseModel::create(['id' => 1, 'column' => 'value']);

        $this->assertTrue($model->related->is($model));
    }

    public function testRelationWithSQLite()
    {
        $this->db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'sqlite');
        $this->db->getConnection('sqlite')->statement('CREATE TABLE IF NOT EXISTS test(id INTEGER, column TEXT)');

        $clickhouseModel = ClickHouseModel::create(['id' => 1, 'column' => 'value']);
        $sqliteModel = SQLiteModel::create(['id' => 1, 'column' => 'another_value']);

        $this->assertTrue($clickhouseModel->sqliteRelated->is($sqliteModel));

        $this->db->getConnection('sqlite')->statement('DROP TABLE IF EXISTS test');
    }

    private function setUpEloquent()
    {
        $this->db = new DB;

        $this->db->getDatabaseManager()->extend('clickhouse', function ($config, $name) {
            $config['name'] = $name;

            unset($config['database']);

            return new Connection(database: $config['database'] ?? '', config: $config);
        });

        $this->db->addConnection([
            'driver' => 'clickhouse',
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => getenv('CLICKHOUSE_PORT'),
            'database' => getenv('CLICKHOUSE_DATABASE'),
            'username' => getenv('CLICKHOUSE_USERNAME'),
            'password' => getenv('CLICKHOUSE_PASSWORD'),
        ], 'clickhouse');

        $this->db->bootEloquent();
    }

    private function createTestTable()
    {
        $this->clickhouseClient()->write('CREATE TABLE IF NOT EXISTS `test` (`id` UInt32, `column` String) ENGINE = Memory');
    }

    private function truncateTestTable()
    {
        $this->clickhouseClient()->write('TRUNCATE TABLE `test`');
    }

    private function clickhouseClient()
    {
        return $this->db->getConnection('clickhouse')->getClient();
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

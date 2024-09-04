<?php

namespace SwooleTW\ClickHouse\Tests\Laravel;

use Illuminate\Database\Capsule\Manager as DB;
use SwooleTW\ClickHouse\Laravel\Connection;
use SwooleTW\ClickHouse\Laravel\Eloquent\Model;
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
        TestModel::create(['id' => 1, 'column' => 'value']);

        $this->assertEquals(
            [['id' => 1, 'column' => 'value']],
            TestModel::all()->toArray()
        );
    }

    public function testUpdate()
    {
        TestModel::create(['id' => 1, 'column' => 'value']);
        TestModel::where('id', 1)->update(['column' => 'new_value']);

        $this->assertEquals(
            [['id' => 1, 'column' => 'new_value']],
            TestModel::all()->toArray()
        );
    }

    public function testDelete()
    {
        TestModel::create(['id' => 1, 'column' => 'value']);
        TestModel::where('id', 1)->delete();

        $this->assertEquals(
            [],
            TestModel::all()->toArray()
        );
    }

    private function setUpEloquent()
    {
        $this->db = new DB;

        $this->db->getDatabaseManager()->extend('clickhouse', function ($config) {
            return new Connection(database: $config['database'] ?? '', config: $config);
        });

        $this->db->addConnection([
            'driver' => 'clickhouse',
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => getenv('CLICKHOUSE_PORT'),
            'database' => getenv('CLICKHOUSE_DATABASE'),
            'username' => getenv('CLICKHOUSE_USERNAME'),
            'password' => getenv('CLICKHOUSE_PASSWORD'),
        ]);

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
        return $this->db->getConnection()->getClient();
    }
}

class TestModel extends Model
{
    public $timestamps = false;

    protected $table = 'test';

    protected $fillable = ['id', 'column'];
}

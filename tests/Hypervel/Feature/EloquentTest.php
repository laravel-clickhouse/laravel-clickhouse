<?php

namespace ClickHouse\Tests\Hypervel\Feature;

use ClickHouse\Hypervel\Eloquent\Builder;
use ClickHouse\Hypervel\Eloquent\Model;
use ClickHouse\Hypervel\Facades\Schema;
use UnitEnum;

class EloquentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('hypervel_eloquent_test');
        Schema::create('hypervel_eloquent_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('hypervel_eloquent_test');

        parent::tearDown();
    }

    public function testQueryReturnsClickHouseBuilder()
    {
        $this->assertInstanceOf(Builder::class, HypervelEloquentTestModel::query());
    }

    public function testCreateAndFind()
    {
        HypervelEloquentTestModel::create(['id' => 1, 'name' => 'first']);

        $model = HypervelEloquentTestModel::query()->find(1);

        $this->assertNotNull($model);
        $this->assertSame('first', $model->name);
    }

    public function testDeleteWithLightweight()
    {
        HypervelEloquentTestModel::create(['id' => 1, 'name' => 'first']);
        HypervelEloquentTestModel::create(['id' => 2, 'name' => 'second']);

        HypervelEloquentTestModel::query()->where('id', 1)->delete(lightweight: true);

        $this->assertSame(1, HypervelEloquentTestModel::query()->count());
    }
}

class HypervelEloquentTestModel extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'hypervel_eloquent_test';

    protected UnitEnum|string|null $connection = 'clickhouse';

    protected array $guarded = [];
}

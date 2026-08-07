<?php

namespace ClickHouse\Tests\Hypervel\Unit\Schema;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Hypervel\Schema\Blueprint;
use ClickHouse\Tests\Hypervel\Unit\TestCase;

class GrammarTest extends TestCase
{
    public function testBasicCreateTable()
    {
        $blueprint = $this->newBlueprint();
        $blueprint->create();
        $blueprint->string('name', 32);
        $blueprint->dateTime('created_at');
        $blueprint->partitionBy('toYYYYMM(created_at)');
        $blueprint->orderBy('created_at');

        $statements = $blueprint->toSql();

        $this->assertSame(
            'CREATE TABLE events (name FixedString(32), created_at DateTime) '
            .'ENGINE = MergeTree() PARTITION BY toYYYYMM(created_at) ORDER BY (created_at)',
            $statements[0]
        );
    }

    public function testColumnTypes()
    {
        $blueprint = $this->newBlueprint();
        $blueprint->create();
        $blueprint->bigInteger('big');
        $blueprint->integer('normal')->unsigned();
        $blueprint->boolean('flag');
        $blueprint->uuid('identifier');
        $blueprint->array('tags', 'String');

        $statements = $blueprint->toSql();

        $this->assertStringContainsString('big Int64', $statements[0]);
        $this->assertStringContainsString('normal UInt32', $statements[0]);
        $this->assertStringContainsString('flag Bool', $statements[0]);
        $this->assertStringContainsString('identifier UUID', $statements[0]);
        $this->assertStringContainsString('tags Array(String)', $statements[0]);
    }

    public function testNullableAndLowCardinalityDecorators()
    {
        $blueprint = $this->newBlueprint();
        $blueprint->create();
        $blueprint->text('status')->nullable()->lowCardinality();

        $statements = $blueprint->toSql();

        $this->assertStringContainsString('status LowCardinality(Nullable(String))', $statements[0]);
    }

    public function testDropWithSync()
    {
        $blueprint = $this->newBlueprint();
        $blueprint->drop()->sync();

        $this->assertSame(['DROP TABLE events SYNC'], $blueprint->toSql());
    }

    public function testRenameColumn()
    {
        $blueprint = $this->newBlueprint();
        $blueprint->renameColumn('old_name', 'new_name');

        $this->assertSame(
            ['ALTER TABLE events RENAME COLUMN old_name TO new_name'],
            $blueprint->toSql()
        );
    }

    protected function newBlueprint(string $table = 'events'): Blueprint
    {
        $connection = new Connection('default');
        $connection->getSchemaBuilder();

        return new Blueprint($connection, $table);
    }
}

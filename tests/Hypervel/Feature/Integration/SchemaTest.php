<?php

namespace ClickHouse\Tests\Hypervel\Feature\Integration;

use ClickHouse\Hypervel\Facades\Schema;
use ClickHouse\Tests\Hypervel\Feature\TestCase;

/**
 * End-to-end verification of the schema introspection contract every driver
 * has to satisfy, which packages that walk Eloquent models (API doc
 * generators, IDE helpers, admin panels) call through the Schema facade.
 * Running the compiled SQL against a real server is the only way to catch a
 * query that is valid PHP but not valid ClickHouse.
 */
class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExistsSync('schema_test');
        Schema::create('schema_test', function ($table) {
            $table->integer('id');
            $table->text('name');
            $table->orderBy('id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExistsSync('schema_test');

        parent::tearDown();
    }

    public function testGetColumnsReturnsTheDocumentedColumnShape()
    {
        $columns = Schema::getColumns('schema_test');

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            foreach (['name', 'type_name', 'type', 'collation', 'nullable', 'default', 'comment', 'auto_increment'] as $key) {
                $this->assertArrayHasKey($key, $column);
            }

            // The documented column shape types these keys as bool, so strict
            // consumers must not receive ClickHouse's UInt8 0/1 here.
            $this->assertIsBool($column['nullable']);
            $this->assertFalse($column['auto_increment']);
        }

        $this->assertSame(['id', 'name'], array_column($columns, 'name'));
    }

    public function testGetIndexesReturnsAnEmptyResultInsteadOfThrowing()
    {
        $this->assertSame([], Schema::getIndexes('schema_test'));
        $this->assertSame([], Schema::getIndexListing('schema_test'));
    }

    public function testGetForeignKeysReturnsAnEmptyResultInsteadOfThrowing()
    {
        $this->assertSame([], Schema::getForeignKeys('schema_test'));
    }
}

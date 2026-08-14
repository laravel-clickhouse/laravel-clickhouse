<?php

namespace ClickHouse\Tests\Feature\Schema;

use ClickHouse\Laravel\Testing\DatabaseMigrations;
use ClickHouse\Tests\Feature\ClickHouseOnlyTestCase;
use Illuminate\Support\Facades\Schema;

/**
 * End-to-end verification of the schema introspection contract every driver has
 * to satisfy, which packages that walk Eloquent models (API doc generators, IDE
 * helpers, admin panels) call through the Schema facade. Running the compiled
 * SQL against a real server is the only way to catch a query that is valid PHP
 * but not valid ClickHouse.
 */
class IntrospectionTest extends ClickHouseOnlyTestCase
{
    use DatabaseMigrations;

    public function testGetColumnsReturnsTheDocumentedColumnShape(): void
    {
        $columns = Schema::connection('clickhouse')->getColumns('ch_events');

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            foreach (['name', 'type_name', 'type', 'collation', 'nullable', 'default', 'comment', 'auto_increment'] as $key) {
                $this->assertArrayHasKey($key, $column);
            }

            $this->assertFalse((bool) $column['auto_increment']);
        }

        $this->assertSame(['id', 'name'], array_column($columns, 'name'));
    }

    public function testGetIndexesReturnsAnEmptyResultInsteadOfThrowing(): void
    {
        $this->assertSame([], Schema::connection('clickhouse')->getIndexes('ch_events'));
        $this->assertSame([], Schema::connection('clickhouse')->getIndexListing('ch_events'));
    }
}

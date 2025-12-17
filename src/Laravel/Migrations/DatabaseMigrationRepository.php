<?php

namespace ClickHouse\Laravel\Migrations;

use ClickHouse\Laravel\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Migrations\DatabaseMigrationRepository as BaseDatabaseMigrationRepository;

class DatabaseMigrationRepository extends BaseDatabaseMigrationRepository
{
    /**
     * {@inheritDoc}
     */
    public function createRepository()
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        if (! $schema instanceof SchemaBuilder) {
            parent::createRepository();

            return;
        }

        $schema->create($this->table, function ($table) {
            $table->text('migration');
            $table->integer('batch');
            $table->orderBy('batch');
        });
    }
}

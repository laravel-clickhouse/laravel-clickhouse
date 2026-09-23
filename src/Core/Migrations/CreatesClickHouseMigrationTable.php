<?php

namespace ClickHouse\Core\Migrations;

use ClickHouse\Core\Contracts\ClickHouseConnection;

/**
 * The ClickHouse migration-table definition shared by every framework
 * bridge. The using class must extend its framework's migration
 * repository, whose getConnection()/$table API this trait relies on.
 */
trait CreatesClickHouseMigrationTable
{
    /** {@inheritDoc} */
    public function createRepository(): void
    {
        if (! $this->getConnection() instanceof ClickHouseConnection) {
            parent::createRepository();

            return;
        }

        $schema = $this->getConnection()->getSchemaBuilder();

        $schema->create($this->table, function ($table) {
            $table->text('migration');
            $table->integer('batch');
            $table->orderBy('batch');
        });
    }
}

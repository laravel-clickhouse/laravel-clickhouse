<?php

namespace ClickHouse\Laravel\Migrations;

use ClickHouse\Core\Migrations\CreatesClickHouseMigrationTable;
use Illuminate\Database\Migrations\DatabaseMigrationRepository as BaseDatabaseMigrationRepository;

class DatabaseMigrationRepository extends BaseDatabaseMigrationRepository
{
    use CreatesClickHouseMigrationTable;
}

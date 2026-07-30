<?php

namespace ClickHouse\Hypervel\Migrations;

use ClickHouse\Core\Migrations\CreatesClickHouseMigrationTable;
use Hypervel\Database\Migrations\DatabaseMigrationRepository as BaseDatabaseMigrationRepository;

class DatabaseMigrationRepository extends BaseDatabaseMigrationRepository
{
    use CreatesClickHouseMigrationTable;
}

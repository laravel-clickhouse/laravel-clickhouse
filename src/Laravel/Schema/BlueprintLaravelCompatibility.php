<?php

namespace ClickHouse\Laravel\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;

/**
 * Laravel 12 added Connection as the first required constructor argument
 * (and a matching $connection property) to Illuminate\Database\Schema\Blueprint.
 * Defining both shapes as separate traits under the same name and picking
 * one at autoload time keeps the Blueprint subclass free of runtime version
 * checks; only one branch is ever loaded. PHPStan analyses both, so the
 * parent constructor call in whichever branch is dead under the installed
 * Laravel version needs a targeted ignore.
 *
 * @see https://github.com/laravel/framework/commit/f29df4740d724f1c36385c9989569e3feb9422df
 *
 * phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 */
if (! property_exists(BaseBlueprint::class, 'connection')) {
    /** @internal Laravel 11 */
    trait BlueprintLaravelCompatibility
    {
        protected Connection $connection;

        public function __construct(Connection $connection, string $table, ?Closure $callback = null)
        {
            parent::__construct($table, $callback);

            $this->connection = $connection;
        }
    }
} else {
    /** @internal Laravel 12+ */
    trait BlueprintLaravelCompatibility
    {
        public function __construct(Connection $connection, string $table, ?Closure $callback = null)
        {
            parent::__construct($connection, $table, $callback);
        }
    }
}

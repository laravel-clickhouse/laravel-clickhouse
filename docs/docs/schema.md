# Schema

## Creating Tables

Use the `Schema` facade with the ClickHouse Blueprint to create tables:

```php
use Illuminate\Support\Facades\Schema;
use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;

Schema::connection('clickhouse')->create('events', function (ClickHouseBlueprint $table) {
    $table->unsignedInteger('id');
    $table->string('name');
    $table->dateTime('created_at');

    $table->engine('MergeTree()');
    $table->orderBy(['id', 'created_at']);
    $table->partitionBy('toYYYYMM(created_at)');
});
```

Generated SQL:

```sql
CREATE TABLE events (
    id UInt32,
    name FixedString(255),
    created_at DateTime
) ENGINE = MergeTree()
  PARTITION BY toYYYYMM(created_at)
  ORDER BY (id, created_at)
```

### Table Engine

The engine defaults to `MergeTree()` if not specified. You may set a default engine in your `config/database.php`:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    // ...
    'engine' => 'ReplacingMergeTree()',
],
```

Complex engines are also supported:

```php
$table->engine("ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}', updated_at)");
```

### ORDER BY

The `orderBy` method accepts an array or variadic arguments:

```php
$table->orderBy(['id', 'created_at']);
$table->orderBy('id', 'created_at');
```

### PARTITION BY

The `partitionBy` method accepts any valid ClickHouse expression:

```php
$table->partitionBy('toYYYYMM(created_at)');
```

## Column Types

| Laravel Method | ClickHouse Type |
|---|---|
| `char($col, $len)` | `FixedString($len)` |
| `string($col, $len)` | `FixedString($len)` (default 255) |
| `tinyText($col)` | `String` |
| `text($col)` | `String` |
| `mediumText($col)` | `String` |
| `longText($col)` | `String` |
| `tinyInteger($col)` | `Int8` |
| `smallInteger($col)` | `Int16` |
| `integer($col)` | `Int32` |
| `mediumInteger($col)` | `Int32` |
| `bigInteger($col)` | `Int64` |
| `unsignedTinyInteger($col)` | `UInt8` |
| `unsignedSmallInteger($col)` | `UInt16` |
| `unsignedInteger($col)` | `UInt32` |
| `unsignedMediumInteger($col)` | `UInt32` |
| `unsignedBigInteger($col)` | `UInt64` |
| `float($col)` | `Float32` |
| `double($col)` | `Float64` |
| `decimal($col, $total, $places)` | `Decimal($total, $places)` |
| `boolean($col)` | `Bool` |
| `enum($col, $values)` | `Enum('val1', 'val2')` |
| `date($col)` | `Date` |
| `dateTime($col)` | `DateTime` |
| `dateTime($col, $precision)` | `DateTime64($precision)` |
| `timestamp($col)` | `DateTime` |
| `timestamp($col, $precision)` | `DateTime64($precision)` |
| `uuid($col)` | `UUID` |
| `binary($col)` | `String` |
| `ipAddress($col)` | `FixedString(45)` |
| `macAddress($col)` | `FixedString(17)` |
| `array($col, $type)` | `Array($type)` |

### Array Columns

Use the `array` method to define array columns with a ClickHouse inner type:

```php
$table->array('tags', 'String');           // Array(String)
$table->array('scores', 'Float64');        // Array(Float64)
$table->array('nested', 'Array(String)');  // Array(Array(String))
```

## Column Modifiers

### Type Decorators

Decorators wrap the column type and are applied in this order: `Unsigned` -> `Nullable` -> `LowCardinality`.

```php
$table->integer('count')->nullable();
// Nullable(Int32)

$table->string('status')->lowCardinality();
// LowCardinality(FixedString(255))

$table->integer('amount')->unsigned();
// UInt32

$table->text('category')->nullable()->lowCardinality();
// LowCardinality(Nullable(String))
```

### Default Values

```php
$table->integer('count')->default(0);
// count Int32 DEFAULT 0

$table->text('status')->default('active');
// status String DEFAULT 'active'

$table->dateTime('created_at')->default(new Expression('now()'));
// created_at DateTime DEFAULT now()

$table->dateTime('created_at')->useCurrent();
// created_at DateTime DEFAULT now()

$table->dateTime('created_at', 3)->useCurrent();
// created_at DateTime64(3) DEFAULT now64(3)
```

### Computed Columns

```php
$table->text('full_name')->virtualAs("concat(first_name, ' ', last_name)");
// full_name String ALIAS concat(first_name, ' ', last_name)

$table->text('full_name')->storedAs("concat(first_name, ' ', last_name)");
// full_name String MATERIALIZED concat(first_name, ' ', last_name)
```

### Column Position

```php
$table->string('name')->first();
// ADD COLUMN name FixedString(255) FIRST

$table->string('name')->after('email');
// ADD COLUMN name FixedString(255) AFTER email
```

## Indexes

ClickHouse requires an algorithm for index creation. Only single-column indexes are supported:

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    // Index with algorithm and granularity
    $table->index('user_id', 'idx_user_id', 'bloom_filter')->granularity(10);
    // SQL: ALTER TABLE events ADD INDEX idx_user_id user_id TYPE bloom_filter GRANULARITY 10

    // Index with default granularity (1)
    $table->index('email', 'idx_email', 'minmax');
    // SQL: ALTER TABLE events ADD INDEX idx_email email TYPE minmax GRANULARITY 1

    // Raw index expression
    $table->rawIndex('lower(email)', 'idx_email_lower');
    // SQL: ALTER TABLE events ADD INDEX idx_email_lower lower(email)

    // Drop an index
    $table->dropIndex('idx_user_id');
    // SQL: ALTER TABLE events DROP INDEX idx_user_id
});
```

> **Note:** Primary key, unique, fulltext, spatial indexes, and foreign keys all throw `RuntimeException` as they are not supported by ClickHouse.

## Modifying Tables

### Adding Columns

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    $table->string('name');
    // SQL: ALTER TABLE events ADD COLUMN name FixedString(255)
});
```

### Adding Columns at Position

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    $table->string('name')->first();
    // SQL: ALTER TABLE events ADD COLUMN name FixedString(255) FIRST

    $table->string('name')->after('email');
    // SQL: ALTER TABLE events ADD COLUMN name FixedString(255) AFTER email
});
```

### Dropping Columns

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    $table->dropColumn('name');
    // SQL: ALTER TABLE events DROP COLUMN name
});
```

### Renaming Tables

```php
Schema::connection('clickhouse')->rename('events', 'activities');
// SQL: RENAME TABLE events TO activities
```

## Dropping Tables

```php
Schema::connection('clickhouse')->drop('events');
// SQL: DROP TABLE events

Schema::connection('clickhouse')->dropIfExists('events');
// SQL: DROP TABLE IF EXISTS events
```

For immediate synchronous drops, use the `sync()` modifier:

```php
Schema::connection('clickhouse')->table('events', function (ClickHouseBlueprint $table) {
    $table->drop()->sync();
    // SQL: DROP TABLE events SYNC

    $table->dropIfExists()->sync();
    // SQL: DROP TABLE IF EXISTS events SYNC
});
```

## Migrations

Standard Laravel migrations work with ClickHouse. The package provides a custom `DatabaseMigrationRepository` that creates a ClickHouse-compatible migrations table automatically.

Run migrations as usual:

```bash
php artisan migrate
```

Example migration:

```php
<?php

use ClickHouse\Laravel\Schema\Blueprint as ClickHouseBlueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::connection('clickhouse')->create('events', function (ClickHouseBlueprint $table) {
            $table->unsignedBigInteger('id');
            $table->unsignedInteger('user_id');
            $table->text('type');
            $table->dateTime('created_at');

            $table->engine('MergeTree()');
            $table->orderBy(['id']);
            $table->partitionBy('toYYYYMM(created_at)');
        });
    }

    public function down(): void
    {
        Schema::connection('clickhouse')->drop('events');
    }
};
```

## Unsupported Operations

The following operations will throw a `RuntimeException`:

- **Column types:** `json`, `jsonb`, `time`, `timeTz`, `year`, `set`
- **Column modifiers:** auto-increment, invisible columns, column comments
- **Indexes:** primary key, unique key, fulltext index, spatial index, foreign key
- **Drop operations:** drop primary key, drop unique key, drop spatial index, drop foreign key
- **Other:** rename index (drop and re-create instead), schema dumping

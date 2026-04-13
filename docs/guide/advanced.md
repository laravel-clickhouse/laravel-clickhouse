# Advanced Topics

- [HTTP Transports](#http-transports)
- [Raw Queries](#raw-queries)
- [Direct Client Access](#direct-client-access)
- [Value Escaping](#value-escaping)
- [Known Limitations](#known-limitations)

## HTTP Transports

The package ships with two HTTP transport drivers for communicating with the ClickHouse server.

### Guzzle (Default)

Guzzle is the recommended transport. It supports parallel query execution out of the box and is well-suited for most Laravel applications.

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'transport' => 'guzzle',
    // ...
],
```

### Curl

The Curl transport is a lightweight alternative built on the phpClickHouse library's cURL wrapper. It does not support parallel query execution.

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'transport' => 'curl',
    // ...
],
```

> **Note:** If you plan to use [Parallel Queries](parallel-queries.md), you must use the Guzzle transport.

## Raw Queries

You can execute raw SQL queries directly through the connection instance. This is useful for ClickHouse-specific statements that are not covered by the Query Builder.

### Select Queries

The `select` method returns an array of associative arrays:

```php
$rows = DB::connection('clickhouse')->select(
    'SELECT count() AS cnt FROM users WHERE active = ?',
    [1]
);

// $rows = [['cnt' => 4821]]
```

### DDL & Other Statements

The `statement` method executes a query and returns a boolean indicating success:

```php
DB::connection('clickhouse')->statement(
    'OPTIMIZE TABLE users FINAL'
);
```

### Affecting Statements

The `affectingStatement` method executes a query and returns the number of affected rows:

```php
$affected = DB::connection('clickhouse')->affectingStatement(
    'ALTER TABLE users DELETE WHERE active = ?',
    [0]
);
```

## Direct Client Access

For low-level operations that go beyond Laravel's database abstraction, you can access the underlying `Client` instance directly:

```php
$client = DB::connection('clickhouse')->getClient();

// Execute a raw query
$client->exec('TRUNCATE TABLE users');

// Prepare and execute a statement
$statement = $client->prepare('SELECT * FROM users WHERE id = ?');
$statement->bindValues([1]);
$statement->execute();
$rows = $statement->fetchAll();
```

## Value Escaping

The `Escaper` class (`ClickHouse\Support\Escaper`) handles value escaping for safe SQL construction. The connection's `escape` method delegates to this class:

```php
DB::connection('clickhouse')->escape('O\'Brien');  // 'O\'Brien'
DB::connection('clickhouse')->escape(42);           // 42
DB::connection('clickhouse')->escape(true);         // 1
DB::connection('clickhouse')->escape(null);         // null
DB::connection('clickhouse')->escape(now());        // '2026-04-10 12:00:00'
DB::connection('clickhouse')->escape([1, 2, 3]);   // [1, 2, 3]
```

### Supported Types

| Type | Behavior |
|---|---|
| `string` | Escaped with `addslashes` and wrapped in single quotes. |
| `int` / `float` | Returned as-is (cast to string). |
| `bool` | Converted to `1` or `0`. |
| `null` | Returned as the string `null`. |
| `DateTimeInterface` | Formatted as `Y-m-d H:i:s` and escaped as a string. |
| `array` | Each element is recursively escaped and joined with commas inside brackets. |
| Objects with `__toString` | Cast to string, then escaped as a string. |

> **Warning:** Binary escaping is not supported and will throw a `RuntimeException`. Strings containing null bytes or invalid UTF-8 sequences will also be rejected.

## Known Limitations

The following features are not available due to ClickHouse's architecture or current package scope:

### Transactions

ClickHouse does not support transactions. Calling `beginTransaction()`, `commit()`, or `rollBack()` will have no effect.

### Unsupported Query Builder Methods

| Method | Reason |
|---|---|
| `insertGetId()` | ClickHouse has no auto-incrementing IDs or `RETURNING` clause. |
| `upsert()` | Not supported. Use `ReplacingMergeTree` engine for deduplication. |
| `update()` with joins | ClickHouse does not support `UPDATE ... JOIN`. Use `joinGet()` or `dictGet()` functions instead. |
| `delete()` with joins | ClickHouse does not support `DELETE ... JOIN`. |
| `lock()` / `sharedLock()` / `lockForUpdate()` | ClickHouse has no row-level locking. |
| `useIndex()` / `forceIndex()` / `ignoreIndex()` | ClickHouse does not support index hints. |

### Unsupported Schema Features

| Feature | Reason |
|---|---|
| Schema dumping | Not supported. `getSchemaState()` throws a `RuntimeException`. |
| Foreign keys | ClickHouse does not support foreign key constraints. |
| Unique keys | ClickHouse does not enforce unique constraints. |
| JSON / JSONB columns | Not available as column types. Use `String` with JSON functions. |
| `time` / `year` columns | Not available. Use `DateTime` or `Date` types. |
| `set` columns | Not available as a column type. |
| Invisible columns | Not supported. |
| Column comments | Not supported in the Schema Builder. |

### Other Limitations

- **No auto-incrementing IDs** -- Eloquent models default to `$incrementing = false`. Generate IDs in your application (e.g. UUIDs).

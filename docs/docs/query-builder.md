# Query Builder

The ClickHouse Query Builder extends Laravel's Query Builder with full compatibility for standard operations, plus ClickHouse-specific features like `FINAL`, `ARRAY JOIN`, and `SETTINGS` clauses.

## Basic Queries

All standard Laravel Query Builder methods work as expected with the ClickHouse connection.

### Selecting Data

```php
use Illuminate\Support\Facades\DB;

// Select all columns
DB::connection('clickhouse')->table('events')->get();
// select * from `events`

// Select specific columns
DB::connection('clickhouse')->table('events')->select('id', 'name')->get();
// select `id`, `name` from `events`

// Column alias
DB::connection('clickhouse')->table('events')->select('column as alias')->get();
// select `column` as `alias` from `events`

// Cross-database query
DB::connection('clickhouse')->table('database.table')->get();
// select * from `database`.`table`

// Distinct
DB::connection('clickhouse')->table('events')->select('status')->distinct()->get();
// select distinct `status` from `events`
```

### Where Clauses

```php
// Basic where
$query->where('status', 'active');
// where `status` = 'active'

// Where with operator
$query->where('age', '>', 18);
// where `age` > 18

// whereIn / whereNotIn
$query->whereIn('status', ['active', 'pending']);
// where `status` in ('active', 'pending')

$query->whereNotIn('status', ['banned', 'deleted']);
// where `status` not in ('banned', 'deleted')

// whereBetween
$query->whereBetween('age', [18, 65]);
// where `age` between 18 and 65

// whereNull / whereNotNull
$query->whereNull('deleted_at');
// where `deleted_at` is null

$query->whereNotNull('email');
// where `email` is not null

// whereColumn
$query->whereColumn('updated_at', '>', 'created_at');
// where `updated_at` > `created_at`

// whereAll - all columns must match
$query->whereAll(['first_name', 'last_name'], 'like', '%John%');
// where (`first_name` like '%John%' and `last_name` like '%John%')

// whereAny - any column may match
$query->whereAny(['email', 'phone'], 'like', '%search%');
// where (`email` like '%search%' or `phone` like '%search%')

// whereNone - no column should match
$query->whereNone(['email', 'phone'], 'like', '%spam%');
// where not (`email` like '%spam%' or `phone` like '%spam%')

// whereExists
$query->whereExists(function ($query) {
    $query->from('orders')->whereColumn('orders.user_id', 'users.id');
});
// where exists (select * from `orders` where `orders`.`user_id` = `users`.`id`)

// whereRaw
$query->whereRaw('column = ?', ['value']);
// where column = 'value'
```

### Grouping, Ordering & Having

```php
// Group by
$query->groupBy('status')->get();
// group by `status`

$query->groupBy('status', 'type')->get();
// group by `status`, `type`

// Order by
$query->orderBy('created_at', 'desc')->get();
// order by `created_at` desc

// Random order (uses ClickHouse's randCanonical())
$query->inRandomOrder()->get();
// order by randCanonical()

// Having
$query->groupBy('status')->having('count', '>', 10)->get();
// group by `status` having `count` > 10

// havingBetween
$query->groupBy('status')->havingBetween('count', [1, 100])->get();
// group by `status` having `count` between 1 and 100

// havingNull / havingNotNull
$query->havingNull('column');
// having `column` is null

// havingRaw
$query->havingRaw('count(*) > ?', [10]);
// having count(*) > 10
```

### Limit & Offset

```php
$query->limit(10)->offset(20)->get();
// select * from `table` limit 10 offset 20
```

### Aggregates

```php
DB::connection('clickhouse')->table('events')->count();
// select count(*) as aggregate from `events`

DB::connection('clickhouse')->table('events')->min('duration');
// select min(`duration`) as aggregate from `events`

DB::connection('clickhouse')->table('events')->max('duration');
// select max(`duration`) as aggregate from `events`

DB::connection('clickhouse')->table('events')->sum('amount');
// select sum(`amount`) as aggregate from `events`

DB::connection('clickhouse')->table('events')->avg('score');
// select avg(`score`) as aggregate from `events`

DB::connection('clickhouse')->table('events')->exists();
// select exists(select * from `events`) as `exists`
```

## FINAL Clause

The `FINAL` modifier forces ClickHouse to merge data parts before returning results. This is particularly useful with `ReplacingMergeTree`, `CollapsingMergeTree`, and other merge tree engines that perform background merges.

```php
DB::connection('clickhouse')->table('events', final: true)->get();
// select * from `events` final

DB::connection('clickhouse')->table('events', final: true)
    ->where('user_id', 1)
    ->get();
// select * from `events` final where `user_id` = 1
```

> **Note:** The `FINAL` clause cannot be used with subqueries. Attempting to do so will throw a `LogicException`.

```php
// This will throw a LogicException
DB::connection('clickhouse')->table(
    DB::connection('clickhouse')->table('events'),
    final: true
)->get();
```

## ARRAY JOIN

ClickHouse's `ARRAY JOIN` clause expands array columns into individual rows -- similar to an `UNNEST` operation in standard SQL.

### Basic Array Join

```php
$query->from('events')->arrayJoin('tags')->get();
// select * from `events` array join `tags`
```

### Multiple Columns

```php
$query->from('events')->arrayJoin(['tags', 'scores'])->get();
// select * from `events` array join `tags`, `scores`
```

### With Alias

```php
$query->from('events')->arrayJoin('tags', 'tag')->get();
// select *, `tag` from `events` array join `tags` as `tag`
```

### With Array of Aliases

```php
$query->from('events')->arrayJoin([
    'alias_a' => 'column_a',
    'alias_b' => 'column_b',
    'column_c',
])->get();
// select *, `alias_a`, `alias_b` from `events`
//   array join `column_a` as `alias_a`, `column_b` as `alias_b`, `column_c`
```

### Subquery Array Join

```php
$query->from('events')->arrayJoin(
    DB::connection('clickhouse')->table('tags'),
    'tag'
)->get();
// select *, `tag` from `events` array join (select * from `tags`) as `tag`

// Or using arrayJoinSub directly
$query->from('events')->arrayJoinSub(
    DB::connection('clickhouse')->table('tags'),
    'tag'
)->get();
// select *, `tag` from `events` array join (select * from `tags`) as `tag`
```

### Left Array Join

`LEFT ARRAY JOIN` preserves rows even when the array is empty, producing `NULL` or default values for the expanded columns.

```php
$query->from('events')->leftArrayJoin('tags')->get();
// select * from `events` left array join `tags`

$query->from('events')->leftArrayJoinSub(
    DB::connection('clickhouse')->table('tags'),
    'tag'
)->get();
// select *, `tag` from `events` left array join (select * from `tags`) as `tag`
```

> **Note:** You cannot mix `arrayJoin` and `leftArrayJoin` in the same query. Doing so will throw a `LogicException`.

## Common Table Expressions (WITH)

ClickHouse supports `WITH` clauses (CTEs) for defining named subqueries and scalar expressions.

### Scalar Value

```php
$query->withQuery('value', 'alias')->from('events')->get();
// with 'value' as `alias` select * from `events`
```

### Scalar Subquery

```php
$subquery = DB::connection('clickhouse')->table('events')->selectRaw('count(*)');

$query->withQuery($subquery, 'total')->from('events')->get();
// with (select count(*) from `events`) as `total` select * from `events`
```

### Raw Expression

```php
$query->withQueryRaw('?', 'alias', ['value'])->from('events')->get();
// with 'value' as `alias` select * from `events`
```

### Named Subquery (WITH ... AS)

```php
$subquery = DB::connection('clickhouse')->table('events')
    ->where('status', 'active');

$query->withQuerySub($subquery, 'active_events')->from('active_events')->get();
// with `active_events` as (select * from `events` where `status` = 'active')
//   select * from `active_events`
```

### Recursive CTE

```php
$subquery = DB::connection('clickhouse')->table('categories');

$query->withQueryRecursive($subquery, 'tree')->from('tree')->get();
// with recursive `tree` as (select * from `categories`) select * from `tree`
```

## ClickHouse-Specific Joins

All standard Laravel join methods work as expected. In addition, this package provides ClickHouse-specific join types:

| Method | SQL Type |
|---|---|
| `join()` / `innerJoin()` | `INNER JOIN` |
| `leftJoin()` | `LEFT JOIN` |
| `rightJoin()` | `RIGHT JOIN` |
| `crossJoin()` | `CROSS JOIN` |
| `fullJoin()` | `FULL JOIN` |
| `innerAnyJoin()` | `INNER ANY JOIN` |
| `leftAnyJoin()` | `LEFT ANY JOIN` |
| `rightAnyJoin()` | `RIGHT ANY JOIN` |
| `semiJoin()` | `SEMI JOIN` |
| `rightSemiJoin()` | `RIGHT SEMI JOIN` |
| `antiJoin()` | `ANTI JOIN` |
| `rightAntiJoin()` | `RIGHT ANTI JOIN` |
| `asofJoin()` | `ASOF JOIN` |
| `leftAsofJoin()` | `LEFT ASOF JOIN` |

Every method has a corresponding `*Sub()` variant for subquery joins (e.g. `innerAnyJoinSub()`, `semiJoinSub()`, `leftAsofJoinSub()`).

### Examples

**ANY Join** -- returns at most one matching row from the right table:

```php
$query->from('orders')
    ->leftAnyJoin('users', 'orders.user_id', '=', 'users.id')
    ->get();
// select * from `orders` left any join `users` on `orders`.`user_id` = `users`.`id`
```

**SEMI Join** -- returns rows from the left table that have at least one match:

```php
$query->from('users')
    ->semiJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();
// select * from `users` semi join `orders` on `users`.`id` = `orders`.`user_id`
```

**ASOF Join** -- joins on the closest match for a given condition (useful for time-series data):

```php
$query->from('trades')
    ->asofJoin('quotes', function ($join) {
        $join->on('trades.symbol', '=', 'quotes.symbol')
             ->on('trades.timestamp', '>=', 'quotes.timestamp');
    })
    ->get();
// select * from `trades` asof join `quotes`
//   on `trades`.`symbol` = `quotes`.`symbol` and `trades`.`timestamp` >= `quotes`.`timestamp`
```

**Subquery Join:**

```php
$subquery = DB::connection('clickhouse')->table('orders')
    ->select('user_id', DB::raw('count(*) as order_count'))
    ->groupBy('user_id');

$query->from('users')
    ->innerAnyJoinSub($subquery, 'user_orders', function ($join) {
        $join->on('users.id', '=', 'user_orders.user_id');
    })
    ->get();
// select * from `users` inner any join (select `user_id`, count(*) as order_count
//   from `orders` group by `user_id`) as `user_orders`
//   on `users`.`id` = `user_orders`.`user_id`
```

## Set Operations

ClickHouse supports `UNION`, `INTERSECT`, and `EXCEPT` with optional `DISTINCT` modifiers.

### UNION

```php
$first = DB::connection('clickhouse')->table('events_2024');
$second = DB::connection('clickhouse')->table('events_2025');

// UNION (deduplicated)
$first->union($second)->get();
// (select * from `events_2024`) union (select * from `events_2025`)

// UNION ALL (keep duplicates)
$first->unionAll($second)->get();
// (select * from `events_2024`) union all (select * from `events_2025`)

// UNION DISTINCT (explicit deduplication)
$first->unionDistinct($second)->get();
// (select * from `events_2024`) union distinct (select * from `events_2025`)
```

### INTERSECT

```php
$first = DB::connection('clickhouse')->table('users_a');
$second = DB::connection('clickhouse')->table('users_b');

$first->intersect($second)->get();
// (select * from `users_a`) intersect (select * from `users_b`)

$first->intersectDistinct($second)->get();
// (select * from `users_a`) intersect distinct (select * from `users_b`)
```

### EXCEPT

```php
$all = DB::connection('clickhouse')->table('users');
$banned = DB::connection('clickhouse')->table('banned_users');

$all->except($banned)->get();
// (select * from `users`) except (select * from `banned_users`)

$all->exceptDistinct($banned)->get();
// (select * from `users`) except distinct (select * from `banned_users`)
```

## Where Extensions

### Empty / Not Empty

ClickHouse's `empty()` and `notEmpty()` functions check whether a value is empty (zero-length string, empty array, etc.).

```php
$query->whereEmpty('name')->get();
// where empty(`name`)

$query->whereNotEmpty('name')->get();
// where not empty(`name`)

// Or variants
$query->whereEmpty('email')->orWhereEmpty('phone')->get();
// where empty(`email`) or empty(`phone`)

$query->whereNotEmpty('email')->orWhereNotEmpty('phone')->get();
// where not empty(`email`) or not empty(`phone`)

// Multiple columns
$query->whereEmpty(['first_name', 'last_name'])->get();
// where empty(`first_name`) and empty(`last_name`)
```

These also work in `HAVING` clauses:

```php
$query->groupBy('status')->havingEmpty('name')->get();
// group by `status` having empty(`name`)

$query->groupBy('status')->havingNotEmpty('name')->get();
// group by `status` having not empty(`name`)

$query->groupBy('status')
    ->havingEmpty('name')
    ->orHavingEmpty('email')
    ->get();
// group by `status` having empty(`name`) or empty(`email`)

$query->groupBy('status')
    ->havingNotEmpty('name')
    ->orHavingNotEmpty('email')
    ->get();
// group by `status` having not empty(`name`) or not empty(`email`)
```

### ClickHouse Date Function Mapping

Laravel's date-based where methods are mapped to ClickHouse functions:

```php
$query->whereDate('created_at', '2024-01-01')->get();
// where toDate(`created_at`) = '2024-01-01'

$query->whereDay('created_at', 15)->get();
// where toDayOfMonth(`created_at`) = 15

$query->whereMonth('created_at', 6)->get();
// where toMonth(`created_at`) = 6

$query->whereYear('created_at', 2024)->get();
// where toYear(`created_at`) = 2024

$query->whereTime('created_at', '10:20:30')->get();
// where toTime(`created_at`) = toTime(toDateTime('1970-01-01 10:20:30'))
```

| Laravel Method | ClickHouse Function |
|---|---|
| `whereDate()` | `toDate()` |
| `whereDay()` | `toDayOfMonth()` |
| `whereMonth()` | `toMonth()` |
| `whereYear()` | `toYear()` |
| `whereTime()` | `toTime()` |

## Settings Clause

ClickHouse allows appending `SETTINGS` to queries for per-query configuration.

```php
// Single setting
$query->from('events')
    ->settings('max_rows_to_read', 1000000)
    ->get();
// select * from `events` settings `max_rows_to_read` = 1000000

// Multiple settings via array
$query->from('events')
    ->settings(['max_threads' => 4, 'optimize_read_in_order' => 1])
    ->get();
// select * from `events` settings `max_threads` = 4, `optimize_read_in_order` = 1

// Chaining settings
$query->from('events')
    ->settings('max_threads', 4)
    ->settings('max_rows_to_read', 1000000)
    ->get();
// select * from `events` settings `max_threads` = 4, `max_rows_to_read` = 1000000
```

Duplicate keys overwrite previous values:

```php
$query->from('events')
    ->settings('max_threads', 2)
    ->settings('max_threads', 8)
    ->get();
// select * from `events` settings `max_threads` = 8
```

## Insert, Update, Delete

### Insert

Standard `insert()` works as expected:

```php
DB::connection('clickhouse')->table('events')->insert([
    'id' => 1,
    'name' => 'page_view',
]);
// insert into `events` (`id`, `name`) values (?, ?)

// Batch insert
DB::connection('clickhouse')->table('events')->insert([
    ['id' => 1, 'name' => 'page_view'],
    ['id' => 2, 'name' => 'click'],
]);
// insert into `events` (`id`, `name`) values (?, ?), (?, ?)
```

### Update

Updates use ClickHouse's `ALTER TABLE ... UPDATE` syntax:

```php
DB::connection('clickhouse')->table('events')
    ->where('id', 1)
    ->update(['name' => 'updated_event']);
// alter table `events` update `name` = ? where `id` = ?
```

> **Note:** Update with joins is not supported and will throw a `LogicException`. Use `joinGet` or `dictGet` functions instead.

### Delete

**Standard delete** uses `ALTER TABLE ... DELETE`:

```php
DB::connection('clickhouse')->table('events')
    ->where('status', 'expired')
    ->delete();
// alter table `events` delete where `status` = ?
```

**Lightweight delete** uses the `DELETE FROM` syntax, which is faster but has different semantics:

```php
DB::connection('clickhouse')->table('events')
    ->where('status', 'expired')
    ->delete(lightweight: true);
// delete from `events` where `status` = ?
```

You can enable lightweight deletes globally via the connection configuration:

```php
// config/database.php
'clickhouse' => [
    // ...
    'use_lightweight_delete' => true,
],
```

**Delete with partition** restricts the delete operation to a specific partition:

```php
DB::connection('clickhouse')->table('events')
    ->where('status', 'expired')
    ->delete(partition: '202401');
// alter table `events` delete in partition ? where `status` = ?
```

**Lightweight delete with partition:**

```php
DB::connection('clickhouse')->table('events')
    ->where('status', 'expired')
    ->delete(lightweight: true, partition: '202401');
// delete from `events` in partition ? where `status` = ?
```

> **Note:** Delete with joins is not supported and will throw a `LogicException`.

### Truncate

```php
DB::connection('clickhouse')->table('events')->truncate();
// truncate table `events`
```

## Unsupported Operations

The following methods throw a `LogicException` when called, as they are not supported by ClickHouse:

| Method | Reason |
|---|---|
| `insertGetId()` | ClickHouse does not support insert get id |
| `upsert()` | ClickHouse does not support upsert |
| `lock()` | ClickHouse does not support locking |
| `useIndex()` | ClickHouse does not support specifying indexes |
| `forceIndex()` | ClickHouse does not support specifying indexes |
| `ignoreIndex()` | ClickHouse does not support specifying indexes |

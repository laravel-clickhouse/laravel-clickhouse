# Eloquent

## Defining Models

To get started, create an Eloquent model that extends `ClickHouse\Laravel\Eloquent\Model`. This base class is abstract, so your model must extend it directly:

```php
<?php

namespace App\Models;

use ClickHouse\Laravel\Eloquent\Model;

class Event extends Model
{
    protected $connection = 'clickhouse';

    protected $table = 'events';
}
```

> **Note:** The ClickHouse Eloquent model sets `$incrementing = false` by default, since ClickHouse does not support auto-incrementing primary keys.

## Model Configuration

The following properties can be configured on your model:

| Property | Default | Description |
|---|---|---|
| `$connection` | `null` | Set to `'clickhouse'` to use the ClickHouse connection. |
| `$table` | *(convention)* | The table associated with the model. |
| `$primaryKey` | `'id'` | The primary key for the model. |
| `$keyType` | `'string'` | The data type of the primary key. |
| `$incrementing` | `false` | Whether the primary key is auto-incrementing. Always `false` for ClickHouse. |
| `$timestamps` | `true` | Whether the model uses `created_at` and `updated_at` columns. |
| `$dateFormat` | `null` | The storage format of the model's date columns. |

```php
class Event extends Model
{
    protected $connection = 'clickhouse';

    protected $table = 'events';

    protected $primaryKey = 'event_id';

    public $timestamps = false;
}
```

## Querying

Standard Eloquent query methods work as expected. All [ClickHouse Query Builder](query-builder.md) features are available through the Eloquent builder:

```php
// Basic queries
$events = Event::all();
$event = Event::find('evt_001');
$event = Event::where('user_id', 1)->first();
$count = Event::where('type', 'click')->count();

// Chained conditions
$events = Event::where('type', 'click')
    ->where('created_at', '>', '2024-01-01')
    ->orderBy('created_at', 'desc')
    ->limit(100)
    ->get();

// Aggregates
$total = Event::where('type', 'purchase')->sum('amount');

// ClickHouse-specific: FINAL modifier
$events = Event::from('events', final: true)->get();
// SQL: SELECT * FROM events FINAL
```

## Inserting

You may use `Model::create()` or `$model->save()` to insert records. Since ClickHouse does not support auto-incrementing IDs, you must provide the primary key value explicitly:

```php
// Using create
$event = Event::create([
    'id' => 'evt_001',
    'user_id' => 42,
    'type' => 'click',
    'created_at' => now(),
]);

// Using save
$event = new Event;
$event->id = 'evt_002';
$event->user_id = 42;
$event->type = 'purchase';
$event->save();

// Bulk insert
Event::insert([
    ['id' => 'evt_003', 'user_id' => 1, 'type' => 'click'],
    ['id' => 'evt_004', 'user_id' => 2, 'type' => 'view'],
]);
```

> **Note:** `insertGetId` is not supported and will throw a `LogicException`.

## Deleting

ClickHouse provides multiple deletion strategies. The Eloquent builder supports all of them through the `delete` and `forceDelete` methods:

```php
// Standard mutation delete (ALTER TABLE ... DELETE)
Event::where('user_id', 1)->delete();
// SQL: ALTER TABLE events DELETE WHERE user_id = ?

// Lightweight delete (DELETE FROM)
Event::where('user_id', 1)->delete(lightweight: true);
// SQL: DELETE FROM events WHERE user_id = ?

// Delete with partition targeting
Event::where('user_id', 1)->delete(partition: '202301');
// SQL: ALTER TABLE events DELETE IN PARTITION '202301' WHERE user_id = ?

// Combine lightweight delete with partition
Event::where('user_id', 1)->delete(lightweight: true, partition: '202301');
// SQL: DELETE FROM events IN PARTITION '202301' WHERE user_id = ?

// Force delete (bypasses onDelete callbacks)
Event::where('user_id', 1)->forceDelete(lightweight: true);
// SQL: DELETE FROM events WHERE user_id = ?

Event::where('user_id', 1)->forceDelete(lightweight: true, partition: '202301');
// SQL: DELETE FROM events IN PARTITION '202301' WHERE user_id = ?
```

**Standard delete** uses `ALTER TABLE ... DELETE`, which is a heavy mutation operation. **Lightweight delete** uses `DELETE FROM`, which marks rows as deleted without immediately removing them. Use lightweight deletes for better performance in most cases.

## Relations

Standard Eloquent relations work with ClickHouse models. Because ClickHouse does not support foreign key constraints or `UPDATE`/`DELETE` with joins, all relations are resolved through separate queries — which is exactly how Eloquent loads them by default.

### Cross-Connection Relations

The most common pattern is pairing a ClickHouse analytics model with a standard relational model (MySQL, PostgreSQL, etc.). Laravel's Eloquent handles cross-connection relations transparently.

**User model (MySQL) with a `hasMany` to ClickHouse events:**

```php
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// app/Models/User.php  (MySQL connection)
class User extends Model
{
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'user_id');
    }
}
```

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// app/Models/Event.php  (ClickHouse connection)
class Event extends Model
{
    protected $connection = 'clickhouse';
    protected $table = 'events';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

```php
// Retrieve all events for a user
$events = User::find(42)->events;

// Retrieve the user who triggered an event
$user = Event::find('evt_001')->user;
```

### Same-Connection Relations

Relations between two ClickHouse models work the same way:

```php
use App\Models\PageView;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// app/Models/Page.php
class Page extends Model
{
    protected $connection = 'clickhouse';
    protected $table = 'pages';

    public function views(): HasMany
    {
        return $this->hasMany(PageView::class, 'page_id');
    }
}
```

```php
use App\Models\Page;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// app/Models/PageView.php
class PageView extends Model
{
    protected $connection = 'clickhouse';
    protected $table = 'page_views';

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
```

```php
$totalViews = Page::find('home')->views()->count();
```

### Eager Loading

Eager loading with `with()` works as expected and avoids N+1 queries. Each relation is resolved with a separate query:

```php
// Load users with their ClickHouse events
$users = User::with('events')->get();

foreach ($users as $user) {
    echo $user->events->count();
}

// Nested eager loading
$users = User::with('events.page')->get();
```

### Relation Constraints

You can apply query constraints on any relation the same way you would with standard Eloquent:

```php
$clickEvents = User::find(42)
    ->events()
    ->where('type', 'click')
    ->where('created_at', '>=', now()->subDays(7))
    ->orderBy('created_at', 'desc')
    ->get();
```

### Limitations

The following relation types are not supported due to ClickHouse's architecture:

- **`belongsToMany`** — requires a pivot table with joins, which ClickHouse does not support.
- **`hasManyThrough` / `hasOneThrough`** — these compile to a join query that ClickHouse cannot execute.
- **Attaching / detaching pivot records** — `attach()`, `detach()`, and `sync()` are unavailable.

## Limitations

The following Eloquent features are not supported due to ClickHouse's architecture:

- **Auto-increment** - ClickHouse does not support auto-incrementing columns.
- **insertGetId** - Not available since there are no auto-incrementing IDs.
- **Upsert** - `upsert()` is not supported.
- **Update with joins** - ClickHouse does not support `UPDATE ... JOIN`.
- **Locking** - `sharedLock()` and `lockForUpdate()` are not available.

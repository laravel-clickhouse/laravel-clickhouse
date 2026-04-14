# Parallel Queries

## Overview

The package supports executing multiple ClickHouse queries concurrently using Guzzle's async HTTP pool. This can significantly improve performance when you need to run several independent queries that don't depend on each other.

> **Important:** Parallel queries require the Guzzle transport (the default). See [Transport Note](#transport-note) below.

## Parallel::get

Use `Parallel::get()` to execute multiple Query Builder or Eloquent queries in parallel:

```php
use ClickHouse\Laravel\Parallel;
use App\Models\User;
use App\Models\Post;
use App\Models\Comment;

$results = Parallel::get([
    'users' => User::where('active', 1),
    'posts' => Post::where('published', true),
    'comments' => Comment::latest(),
]);

// $results['users']    -> Illuminate\Database\Eloquent\Collection of User models
// $results['posts']    -> Illuminate\Database\Eloquent\Collection of Post models
// $results['comments'] -> Illuminate\Database\Eloquent\Collection of Comment models
```

You may also use numeric keys:

```php
$results = Parallel::get([
    User::where('active', 1),
    Post::where('published', true),
]);

// $results[0] -> Collection of User models
// $results[1] -> Collection of Post models
```

Both Query Builder and Eloquent Builder instances are accepted. When using Eloquent Builder, results are properly hydrated as Eloquent models with eager-loaded relations:

```php
$results = Parallel::get([
    'users' => User::with('profile')->where('active', 1),
    'events' => DB::connection('clickhouse')->table('events')->where('type', 'click'),
]);

// $results['users']  -> Collection of User models (with profile relation loaded)
// $results['events'] -> Collection of stdClass objects
```

> **Note:** All queries must use the same connection. Passing queries from different connections will throw an `InvalidArgumentException`.

## Connection-Level Parallel

For lower-level control, use `selectParallelly()` directly on the connection with raw SQL:

```php
$connection = DB::connection('clickhouse');

$results = $connection->selectParallelly([
    'users' => [
        'sql' => 'SELECT * FROM users WHERE active = ?',
        'bindings' => [1],
    ],
    'stats' => [
        'sql' => 'SELECT COUNT(*) as total FROM events',
        'bindings' => [],
    ],
]);

// $results['users'] -> array of associative arrays
// $results['stats'] -> array of associative arrays
```

Each query entry requires `sql` and `bindings` keys.

## Error Handling

When one or more parallel queries fail, a `ParallelQueryException` is thrown. This exception provides access to both successful responses and errors:

```php
use ClickHouse\Exceptions\ParallelQueryException;

try {
    $results = Parallel::get([
        'users' => User::where('active', 1),
        'invalid' => Post::from('nonexistent_table'),
    ]);
} catch (ParallelQueryException $e) {
    // Get all successful responses
    $responses = $e->getResponses();

    // Get all errors (array of Throwable instances)
    $errors = $e->getErrors();

    foreach ($errors as $key => $error) {
        logger()->error("Query '{$key}' failed: {$error->getMessage()}");
    }
}
```

Partial success is possible -- some queries may succeed while others fail. The exception wraps all failures, and the error keys correspond to the query keys you provided.

## Transport Note

Parallel query execution requires the **Guzzle transport**, which is the default transport for the ClickHouse client. Guzzle's async HTTP pool enables true concurrent execution of multiple requests.

If you have explicitly set the transport to `curl` in your configuration, parallel queries will not be available. Ensure your configuration uses the default:

```php
// config/database.php
'clickhouse' => [
    'driver' => 'clickhouse',
    // ...
    'transport' => 'guzzle', // Required for parallel queries (this is the default)
],
```

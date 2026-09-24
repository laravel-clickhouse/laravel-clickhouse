# Installation & Configuration

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Environment Variables](#environment-variables)
- [Multiple Connections](#multiple-connections)
- [Verifying the Connection](#verifying-the-connection)

## Requirements

Before installing the package, make sure your environment meets the following requirements:

- **PHP** >= 8.2
- **Laravel** >= 11.0
- **ClickHouse Server** (any currently supported version)

## Installation

Install the package via Composer:

```bash
composer require laravel-clickhouse/laravel-clickhouse
```

The package uses Laravel's auto-discovery, so the service provider will be registered automatically. No additional setup is required.

## Configuration

Add a `clickhouse` connection to your `config/database.php` file under the `connections` array:

```php
'connections' => [

    // ... other connections

    'clickhouse' => [
        'driver' => 'clickhouse',
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'database' => env('CLICKHOUSE_DATABASE', 'default'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'https' => env('CLICKHOUSE_HTTPS', false),
        'transport' => env('CLICKHOUSE_TRANSPORT', 'guzzle'),
        'timeout' => env('CLICKHOUSE_TIMEOUT'),
        'connect_timeout' => env('CLICKHOUSE_CONNECT_TIMEOUT'),
        'datetime_precision' => env('CLICKHOUSE_DATETIME_PRECISION', 'second'),
        'engine' => env('CLICKHOUSE_ENGINE'),
        'use_lightweight_delete' => env('CLICKHOUSE_USE_LIGHTWEIGHT_DELETE', false),
    ],

],
```

### Configuration Options

| Option | Default | Description |
|---|---|---|
| `driver` | `'clickhouse'` | Must be set to `clickhouse`. |
| `host` | `'127.0.0.1'` | The ClickHouse server hostname or IP address. |
| `port` | `8123` | The ClickHouse HTTP interface port. |
| `database` | `'default'` | The database name. |
| `username` | `'default'` | The username for authentication. |
| `password` | `''` | The password for authentication. |
| `https` | `false` | Enables TLS connections to ClickHouse. |
| `transport` | `'guzzle'` | The HTTP transport driver. Supported: `'guzzle'`, `'curl'`. |
| `timeout` | `null` | Maximum seconds to wait for a request to complete. `null` keeps the transport default (Guzzle: no limit; Curl: `20`). `0` disables the limit. Floats are allowed. |
| `connect_timeout` | `null` | Maximum seconds to wait while connecting to the server. `null` keeps the transport default (Guzzle: no explicit limit, so the underlying HTTP handler default applies; Curl: `5`). `0` disables the limit. Floats are allowed. |
| `datetime_precision` | `'second'` | How `DateTimeInterface` query bindings and `Values`-format insert values are rendered: `'second'` truncates them, `'microsecond'` keeps sub-second precision for `DateTime64` columns. See [DateTime and DateTime64 Values](query-builder.md#datetime-and-datetime64-values). |
| `engine` | `null` | The default table engine for migrations (e.g. `'MergeTree()'`). When not set, defaults to `MergeTree()`. |
| `use_lightweight_delete` | `false` | When `true`, Eloquent `delete()` uses lightweight `DELETE` statements instead of `ALTER TABLE ... DELETE`. |

## Environment Variables

Add the following variables to your `.env` file:

```dotenv
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_HTTPS=
CLICKHOUSE_TRANSPORT=guzzle
CLICKHOUSE_TIMEOUT=
CLICKHOUSE_CONNECT_TIMEOUT=
CLICKHOUSE_DATETIME_PRECISION=second
CLICKHOUSE_ENGINE=
CLICKHOUSE_USE_LIGHTWEIGHT_DELETE=false
```

## Multiple Connections

You may define multiple ClickHouse connections by adding additional entries to the `connections` array:

```php
'connections' => [

    'clickhouse' => [
        'driver' => 'clickhouse',
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'database' => env('CLICKHOUSE_DATABASE', 'default'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'https' => env('CLICKHOUSE_HTTPS', false),
        'transport' => 'guzzle',
    ],

    'clickhouse_analytics' => [
        'driver' => 'clickhouse',
        'host' => env('CLICKHOUSE_ANALYTICS_HOST', '127.0.0.1'),
        'port' => env('CLICKHOUSE_ANALYTICS_PORT', 8123),
        'database' => env('CLICKHOUSE_ANALYTICS_DATABASE', 'analytics'),
        'username' => env('CLICKHOUSE_ANALYTICS_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_ANALYTICS_PASSWORD', ''),
        'https' => env('CLICKHOUSE_HTTPS', false),
        'transport' => 'guzzle',
    ],

],
```

Use a specific connection via the `DB` facade or on your Eloquent model:

```php
// Query Builder
DB::connection('clickhouse_analytics')->table('events')->get();

// Eloquent Model
class Event extends \ClickHouse\Laravel\Eloquent\Model
{
    protected $connection = 'clickhouse_analytics';
}
```

## Verifying the Connection

After configuring your connection, verify it is working by running a simple query:

```php
DB::connection('clickhouse')->select('SELECT 1');
```

You can also use Artisan Tinker:

```bash
php artisan tinker
>>> DB::connection('clickhouse')->select('SELECT 1')
```

A successful response returns:

```php
[
    ['1' => 1],
]
```

# Upgrade Guide

## Upgrading to v2.0.0 from 1.x

v2.0.0 restructures the package around a framework-agnostic core so that a
single codebase powers both the Laravel and the new [Hypervel](./hypervel.md)
bridge. Laravel applications keep working with at most an import rename.

### High-impact changes

#### Core namespaces moved under `ClickHouse\Core`

The framework-agnostic classes moved into the `ClickHouse\Core` namespace:

| v1 | v2 |
|----|----|
| `ClickHouse\Client\*` | `ClickHouse\Core\Client\*` |
| `ClickHouse\Enums\*` | `ClickHouse\Core\Enums\*` |
| `ClickHouse\Exceptions\*` | `ClickHouse\Core\Exceptions\*` |
| `ClickHouse\Support\*` | `ClickHouse\Core\Support\*` |

Update any imports of these classes — most commonly:

```php
// v1
use ClickHouse\Enums\Format;
use ClickHouse\Exceptions\ParallelQueryException;

// v2
use ClickHouse\Core\Enums\Format;
use ClickHouse\Core\Exceptions\ParallelQueryException;
```

Everything under `ClickHouse\Laravel\*` — the classes applications touch
day-to-day (`Model`, `Connection`, `Blueprint`, `Parallel`, the `Schema`
facade) — is unchanged. If your application never imported the core classes
directly, no code changes are required.

#### `illuminate/database` is no longer a direct dependency

The package now supports multiple frameworks, so `illuminate/database` moved
from `require` to `suggest`. Laravel applications always have it installed —
the framework itself provides it — so this changes nothing in practice. If
you use this package outside a full Laravel application (e.g. with Capsule),
require `illuminate/database` in your own `composer.json`.

### Low-impact changes

- **`affectingStatement()` returns `0` instead of `null`-ish values** for
  DELETE / ALTER TABLE mutations where ClickHouse reports no
  `written_rows`. (Laravel's contract requires an `int`.)
- **`insert()` gained an optional `Format $format` parameter.** If you
  subclass the query builder and override `insert()`, update your override's
  signature to match.
- **Grammar internals moved into shared core traits.** If you extended
  `ClickHouse\Laravel\Query\Grammar` or `ClickHouse\Laravel\Schema\Grammar`
  and overrode protected methods, verify your overrides still line up — the
  methods now live in `ClickHouse\Core\Query\CompilesClickHouseQueries` and
  `ClickHouse\Core\Schema\CompilesClickHouseSchema` respectively (behaviour
  is unchanged).

### New in v2

- **Hypervel 0.4 bridge** under `ClickHouse\Hypervel\*` — see
  [Hypervel Support](./hypervel.md).
- **`insert(..., format: Format::JSONEachRow)`** for fast bulk inserts — see
  [Query Builder](./query-builder.md#insert-using-an-input-format).

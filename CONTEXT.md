# Laravel ClickHouse

A ClickHouse database driver whose framework-agnostic core is mounted onto
Laravel and Hypervel through one thin bridge per framework.

## Language

**Core**:
The framework-agnostic part of the package (`ClickHouse\Core\*`) — everything
that does not depend on a specific framework. Split into two tiers: standalone
classes and core traits.

**Standalone (core tier)**:
Core classes that work without any framework installed: the HTTP client
(`Core\Client`), enums, exceptions and support utilities. The default PHPStan
configuration can analyse them on their own.

**Core trait (core tier)**:
A trait or contract in `ClickHouse\Core\*` that carries the shared
implementation (SQL compilation, schema DDL, connection client behaviour, ...)
and only takes effect when mounted onto a bridge class.
_Avoid_: bridge trait (they live in core, not in a bridge)

**Bridge**:
The per-framework thin layer (`ClickHouse\Laravel\*`, `ClickHouse\Hypervel\*`)
which mounts the core onto a concrete framework and owns framework-specific
registration and resource lifecycle hooks. It contains no duplicated shared
query logic. "Bridge" in the Symfony sense (TwigBridge, MonologBridge), not
the GoF design pattern.
_Avoid_: adapter, integration, port

**Mount**:
What a bridge does to the core: pick the framework parent class, `use` the
core traits, and bind framework classes via class-string constants.

**Driver**:
The `'driver' => 'clickhouse'` identifier in a framework's database config
that routes a connection to this package. Not a synonym for bridge.

**Transport**:
The HTTP mechanism the client sends queries through — Guzzle (default) or
Curl (phpclickhouse).

**Session**:
A ClickHouse HTTP session (`ClickHouse\Core\Client\Session`): an id every
request carries so the server shares state — chiefly temporary tables —
across them, dropped once its timeout passes without a request. Opened for
the duration of a `Connection::session()` callback; on Hypervel it belongs
to the coroutine's pooled connection and never survives a release back to
the pool.
_Avoid_: transaction (ClickHouse has none; a session shares state, it does
not isolate or roll back)

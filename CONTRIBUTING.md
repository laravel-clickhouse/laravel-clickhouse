# Contributing

## Quick start

```bash
git clone https://github.com/laravel-clickhouse/laravel-clickhouse.git
cd laravel-clickhouse
make install   # root vendor: Core + Laravel toolchain
make up        # ClickHouse in Docker (every test target needs it)
make test      # Core + Laravel suites
```

This path covers the framework-free core and the Laravel bridge — most
contributions never need more than this.

## The two bridges

The package mounts one framework-agnostic core onto two frameworks: Laravel
(`ClickHouse\Laravel\*`) and [Hypervel](https://hypervel.org)
(`ClickHouse\Hypervel\*`), a Laravel-style framework running on Swoole
coroutines. All shared logic lives in `ClickHouse\Core\*`; each bridge is a
thin mounting layer. `CLAUDE.md` documents the architecture rules,
`CONTEXT.md` the terminology, and `docs/adr/` the decisions behind them.

What this means for your change:

- **Touching `src/Core` touches both bridges.** Both test suites must pass;
  CI runs both on every push.
- **Behavioural test scenarios are mirrored.** A scenario asserting
  behaviour (exception type/message, return value, execution dispatch)
  needs a twin in the other bridge's suite; a scenario asserting compiled
  SQL output lives in the Laravel unit suite only. Feature scenarios always
  mirror. See `docs/adr/0002-unit-test-mirroring-split-by-assertion-target.md`.
- **No Swoole? No problem.** Write the mirror by following the counterpart
  test line-by-line (only FQCNs and property types differ), open the PR,
  and the CI `hypervel` job verifies it. Running Hypervel tests locally is
  optional.

## Running the full suite

```bash
make test-all        # Core + Laravel + Hypervel, one command (needs Docker)
make test-hypervel   # Hypervel suite alone, inside a Swoole container
```

The Hypervel toolchain conflicts with the root dev dependencies
(`orchestra/testbench`, PHPUnit 11 vs 13), so it lives in an isolated
vendor tree defined by `environments/hypervel/composer.json` — the same
manifest CI uses. The Makefile installs it automatically on first use; the
package source is symlinked in, so local changes apply without reinstalling.

## Quality gates

```bash
composer cs               # code style (Laravel Pint)
composer phpstan          # static analysis: core + laravel configs
composer phpstan:hypervel # hypervel config (needs environments/hypervel installed)
```

All three plus both test suites must be green before a PR merges. Commit
messages use conventional commit prefixes (`feat:`, `fix:`, `test:`, ...)
in English.

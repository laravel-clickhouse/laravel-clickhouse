---
layout: home

hero:
  name: Laravel ClickHouse
  text: ClickHouse Driver for Laravel
  tagline: Eloquent Model, Query Builder, and Schema Builder with full ClickHouse support.
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/laravel-clickhouse/laravel-clickhouse

features:
  - title: Eloquent Model
    details: Use familiar Eloquent patterns with ClickHouse. Non-incrementing IDs, scopes, and collections work out of the box.
  - title: Query Builder
    details: Full Laravel Query Builder compatibility plus ClickHouse extensions — ARRAY JOIN, FINAL, CTE, SEMI/ANTI/ASOF joins, and SETTINGS clause.
  - title: Schema Builder
    details: Create tables with ENGINE, PARTITION BY, ORDER BY, LowCardinality, Array types, and index granularity through Laravel's Schema facade.
  - title: Parallel Queries
    details: Execute multiple queries concurrently via Guzzle's async HTTP pool for significantly improved performance.
  - title: Lightweight DELETE
    details: Delete rows efficiently with lightweight DELETE and partition targeting, avoiding heavy ALTER TABLE mutations.
  - title: Laravel Migrations
    details: Standard artisan migrate works with a ClickHouse-compatible migration repository. No extra setup needed.
---

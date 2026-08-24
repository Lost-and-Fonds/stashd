# Database conventions

Stashd uses PostgreSQL as its only database. Read this document before adding
migrations or database records.

## Schema and PHP names

Migrations use Tempest's `CreateTableStatement` with camelCase field names,
matching PHP record properties (for example, `freeBytes` and `providerItemId`).
PostgreSQL identifiers are quoted when raw SQL is required. Public API JSON is
snake_case and is translated at the HTTP boundary.

## Migrations

Use Tempest schema statements where possible. When a raw statement is needed,
use `App\Database\MigrationSqlStatement`, which compiles the PostgreSQL
identifier form used by the migration set. Keep migration names stable and
test fresh-schema boot plus the relevant upgrade path against PostgreSQL.

Direct upgrades are supported from the PostgreSQL baseline represented by
`2026_08_20_supported_postgres_baseline` (the deployed `83660a7` schema through
`2026_07_15_drop_stash_slug`). The baseline and released post-baseline
migrations are frozen in `tests/fixtures/migrations/frozen.json`; add new
entries when releasing a migration. Older migration implementation is Git
history, not a runtime dependency.

Foreign keys should be explicit and indexed where they participate in common
lookups. Use the existing migration schema helpers for shared primary-key and
foreign-key shapes.

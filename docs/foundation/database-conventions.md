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

Foreign keys should be explicit and indexed where they participate in common
lookups. Use the existing migration schema helpers for shared primary-key and
foreign-key shapes.

# Database and Tempest records

Stashd runs on PostgreSQL. SQLite survives only as the upgrade source that
`stashd:import-sqlite` reads from an older install — not as a runtime target.

## Conventions

- Columns use camelCase to match Tempest record properties. PostgreSQL folds
  unquoted identifiers to lowercase, so camelCase identifiers must be quoted.
- Public API JSON uses snake_case.
- Prefer the query builder over raw SQL. `where('col', $value)` resolves the
  field and quotes it for the active dialect; `where('col = ?', $value)` is a raw
  fragment, is *not* quoted, and silently breaks on PostgreSQL.
- Use `whereNull`, `whereNotNull`, `whereLike`, `whereIn`, `whereField` with a
  `WhereOperator`, and `andWhereGroup` for compound conditions instead of
  hand-written SQL.
- Migrations that need raw DDL go through `MigrationSqlStatement`, which rewrites
  backtick identifiers for the dialect.
- Prefer explicit migrations and tests for persistence changes.
- Index the lookup paths command/job/provider routes depend on.
- Keep transactions short.

## Avoid

- Raw SQL in repositories. The one sanctioned exception is a genuine grouped
  aggregate the builder cannot express: it must quote its identifiers and carry a
  comment saying why (see `StashItemRepository::statusCountsForStash`).
- Unquoted camelCase identifiers anywhere.
- SQLite-only SQL — `PRAGMA`, `sqlite_master`, `EXPLAIN QUERY PLAN`. Tests that
  need schema introspection use the `schemaTableExists` / `schemaColumns` /
  `schemaIndexes` helpers in `tests/Pest.php`.
- Depending on SQLite's loose typing (booleans as 0/1, numbers stored as text).
- Schema changes without migration/rollback thinking.

## Migration safety

Before touching migrations, consider:

```text
replay on an empty PostgreSQL database
idempotence for dev/test
existing data shape
Docker startup behavior
test database behavior
```

## Running the suite

The suite runs against whichever database `DB_CONNECTION` selects. PostgreSQL is
the default and the one that gates a release:

```bash
composer test:parallel
```

# PostgreSQL migration plan and handoff

Status: PostgreSQL is the default runtime on `agent/postgres-migration-foundation`.
SQLite remains supported only as the source the `import-sqlite` upgrade role
reads (`stashd:import-sqlite`) when upgrading an existing install.

## Goal

Replace the default SQLite deployment with a PostgreSQL Docker sidecar while
allowing an existing Docker installation to upgrade in place without losing data.

## Decisions

- Use Tempest's native `PostgresConfig`, migrations, query statements, and
  transactions.
- Keep database columns camelCase. Tempest maps record properties directly to
  column names, and PostgreSQL round-trips quoted camelCase identifiers fine.
- Keep public API JSON snake_case.
- No Redis. PostgreSQL provides durable job claiming with
  `FOR UPDATE SKIP LOCKED`; add Redis only if measured throughput justifies
  another required service.
- **Prefer the query builder over raw SQL.** Raw fragments were the single
  largest source of PostgreSQL breakage (see below).
- The importer is an operator-run console command, not boot-time magic. It runs
  once per install; auto-detection, "skip if already imported", and
  merge-refusal-on-boot would be machinery for a one-time event. Trade-off: the
  operator must run one command, so it is documented in `docker-compose.yml`
  and `.env.example`.

## What made the app dialect-safe

Tempest v3.17.0 shipped `database: quote relation query identifiers` (#2229),
which was the blocker this work waited on. The branch now runs **Tempest
v3.18.0**.

With that in place, one root cause accounted for the bulk of the failures:

- `where('stashId', $value)` resolves the field and quotes it per dialect.
- `where('stashId = ?', $value)` is a **raw fragment**. It is not quoted, so
  PostgreSQL folds `stashId` to `stashid` and the query fails.

`tests/IntegrationTestCase.php` used the raw form in a shared helper, which alone
explained 52 failures. 62 simple raw clauses were converted to the field form and
11 compound ones to `andWhereGroup` / `whereNull` / `whereNotNull` /
`whereLike` / `whereField` with a `WhereOperator`. There are now **no raw
`where()` fragments in `app/` or `tests/`**.

The one deliberate remaining raw query is
`StashItemRepository::statusCountsForStash` — a genuine grouped aggregate the
builder cannot express. It quotes its identifiers and documents why.

SQLite-only SQL was made dialect-aware rather than deleted:

- `PRAGMA table_info` / `PRAGMA index_list` / `sqlite_master` became the
  `schemaColumns` / `schemaIndexes` / `schemaTableExists` helpers in
  `tests/Pest.php` (`information_schema` / `pg_tables` / `pg_indexes` on
  PostgreSQL).
- `EXPLAIN QUERY PLAN` index-usage assertions in `DomainSchemaTest` stay
  SQLite-only on purpose: PostgreSQL's planner picks a sequential scan on empty
  test tables regardless of indexes, so the assertion would prove nothing and
  flake. Index *existence* is still asserted on both dialects, and every query is
  still executed on both to prove the SQL is valid.
- `TempestColumnMappingSpikeTest` is gated to SQLite: it pins SQLite's own error
  text and integer/boolean coercion. `TempestPostgresCompatibilityTest` is the
  PostgreSQL counterpart.
- The SQLite pragma/WAL/file-backup tests in `BootstrapAndHealthTest` are gated;
  the tests that only needed "the active database config" now ask for
  `DatabaseConfig` instead of `SQLiteConfig`.

## Deployment

`docker-compose.yml` ships a pinned `postgres:18-alpine` sidecar with a health
check, a named volume, and **no published ports** (reachable only on the compose
network). `depends_on: condition: service_healthy` keeps the app from racing it.
`copy → paste → docker compose up` still works because compose provides the
database.

The Dockerfile already carried both `pdo_sqlite` and `pdo_pgsql`.

The postgres volume mounts at `/var/lib/postgresql`, **not**
`/var/lib/postgresql/data`: PostgreSQL 18 images set `PGDATA` to
`/var/lib/postgresql/18/docker` and declare their volume one level up.
Mounting the older `.../data` path makes the container refuse to start.
The bind-mounted directory must also be owned by the image's `postgres`
user (uid 70 on Alpine), or initdb cannot create its version directory.

## Upgrading an existing SQLite install

```bash
docker compose up -d postgres
docker compose run --rm stashd import-sqlite /data/stashd.sqlite
docker compose up -d
```

`App\Database\SqliteImporter`:

- refuses to run unless the active connection is PostgreSQL;
- opens the SQLite file **read-only**, so it stays valid as the rollback artifact;
- refuses to merge into a database that already holds rows (`--force` overrides);
- copies tables in an order derived from the legacy schema's own foreign keys
  (topological sort over `PRAGMA foreign_key_list`) rather than creation order,
  because a later migration can rebuild a table and move it after tables that
  reference it;
- copies only columns present on both sides, so an older file with
  since-dropped columns still imports;
- skips `migrations` — Tempest owns migration history on the PostgreSQL side;
- runs entirely in one transaction and verifies per-table row counts before
  committing.

Rollback is "point the old image at the untouched SQLite file".

## Verification

| Check | Result |
| --- | --- |
| PostgreSQL suite (`--parallel --processes=6`) | 515 passed, 23 skipped |
| SQLite suite (`composer test:sqlite:parallel`) | 524 passed, 14 skipped |
| Importer tests (PostgreSQL) | 3 passed |
| `composer test:docker-smoke` | passed |
| `composer test:docker-upgrade` | passed |
| Import of a real 1.4 GB NAS database | 64,534 rows / 23 tables, counts matched |
| PHPStan (`level: max`) | no errors |
| Pint | passed |

## Known issues and follow-ups

1. **Test-harness connection accumulation.** The suite opens roughly one
   PostgreSQL connection per test and does not release them until the worker
   exits, so 535 tests across 6 workers exhausted the default
   `max_connections = 100`. The local dev service was raised to 400. This is a
   harness lifecycle issue, not a production one (each runtime process holds a
   single connection), but it is worth fixing so the suite runs on a stock
   PostgreSQL. Until then, cap parallelism or raise `max_connections`.
2. **Do not run the two suites concurrently.** Running the PostgreSQL suite
   (6 workers) and the SQLite suite (16 workers) at the same time produced 8–29
   spurious failures — filesystem assertions (`file_get_contents` returning
   false) plus the schema race below. Run in isolation each was clean: 515 and
   524 passed.
3. **Rare parallel schema race.** One early run showed 7 failures in `AuthTest` /
   `ActivityControllerTest` with a mix of `relation ... already exists` and
   `relation migrations does not exist`. Two subsequent full runs were clean and
   both files pass in isolation, so it is intermittent rather than a standing
   failure. Likely cause: `MigrationManager::dropAll()` swallows every exception,
   so a partial drop silently leaves a stale schema that the following
   `migrate()` replays over. Worth pinning down before treating the suite as a
   hard gate.
3. **Byte columns were too narrow on PostgreSQL — found by importing the real
   NAS database, fixed.** Tempest's `integer()` compiles to PostgreSQL `INTEGER`
   (max 2147483647) unless a size is given, while SQLite's INTEGER is already
   64-bit. The live Vault has a 4.9 GB asset and 16 assets over the int32 limit,
   so the import failed with `value "2354062259" is out of range for type
   integer`. This was never import-specific: a fresh PostgreSQL install could not
   have stored an asset over ~2.1 GB either, and
   `storage_locations.freeBytes`/`totalBytes` hold whole-disk capacity.
   `assets.sizeBytes` and both `storage_locations` byte columns are now declared
   `DatabaseIntegerSize::BIG`.

   Declared in the original migrations rather than added as an `ALTER` migration:
   fresh databases get the right type immediately, and the test harness does not
   rewrite three tables on every reset. Safe for existing installs because the
   migration hash is computed from the compiled SQL for the active dialect and
   SQLite's `IntegerStatement` ignores the size argument — confirmed by running
   `migrate:validate` against the real NAS database, which the previous code had
   migrated.

   **Lesson worth keeping:** any new byte/size column needs an explicit
   `DatabaseIntegerSize::BIG`; SQLite will never tell you it is wrong.
5. `MigrationSqlStatement` rewrites any quoted identifier containing a backslash
   to `TEXT`. That targets Tempest's PostgreSQL `enum()` support, which references
   a native type name built from the enum FQCN (`"App\Stashes\SyncMode"`) without
   ever emitting the matching `CREATE TYPE` (`CreateEnumTypeStatement` has no
   callers in v3.18.0). The rewrite is load-bearing; treat it as a workaround for
   an upstream gap, not dead code.

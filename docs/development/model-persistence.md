# Shared model persistence

`App\Persistence\Model` supplies UUIDv7 identity, a typed virtual `id`, timestamps,
and explicit `insert()`, `update(...)`, `delete()` and `refresh()` methods. Reads
use Tempest's normal `select()` builder and `get()` hydration. Each model declares
one `IDENTIFIER_TYPE` constant; there is no central class-to-type registry.

`#[Persistence]` defaults every mutation to forbidden. Fenced writes resolve the
small `MutationAuthority` interface. Operations binds that interface to its existing
`PublicationAuthority` singleton, preserving its session checks, transaction
ownership and rollback behavior. The generic base has no Operations dependency.

## Missing rows

Updates and deletes throw `RuntimeException` if their target row no longer exists.
The check happens in the mutation statement, so a concurrent deletion cannot slip
between a preliminary existence query and the write. Failed updates leave the
instance's values and timestamps unchanged.

`update()` accepts value fields. It rejects relationship mutations before writing;
persist related models through their own methods inside the service transaction.

The installed Tempest `Database::execute()` returns `void`; `Query::execute()` and
`UpdateQueryBuilder::execute()` return an optional primary key, not an affected-row
count. `UpdateStatement` and `DeleteStatement` have no `RETURNING` option.
Consequently, the base builds the mutation with Tempest, appends PostgreSQL
`RETURNING "uuid"`, and executes it through Tempest's `Query::fetchFirst()`.
Tempest still owns binding serialization, errors and query events. No query parser
or second connection is introduced. Revisit this small PostgreSQL-specific step
when Tempest exposes affected rows or returning clauses on mutation builders.

## Checkpoint migration

`Checkpoint` is the first Operations model using the foundation. Its insert is
fenced; update and delete remain forbidden. `record()` retains the supplied context
check and joins its protected transaction through the shared insert method.

`UpgradeCheckpoints` renames the stored `id` column to `uuid`, retaining its UUID
and primary key. It adds non-null timestamps. Existing rows receive migration-time
timestamps because their historical publication times were not recorded. Backfill
uses PostgreSQL `date_trunc` to match Tempest's second-precision datetime mapping;
the installed alter builder also lacks PostgreSQL `SET NOT NULL`, so those two
steps use short raw statements inside the normal migration. No other Operations
schema changes are included. Upgrade the schema before running the new model code.

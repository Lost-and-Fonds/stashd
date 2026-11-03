# Shared model persistence

`App\Persistence\Model` supplies UUIDv7 identity, a typed virtual `id`, timestamps,
and explicit `insert()`, `update(...)`, `delete()` and `refresh()` methods. Reads
use Tempest's normal `select()` builder and `get()` hydration.

Identifiers retain a public namespace and UUID, without a type enum or reverse
model registry. A concrete model's lowercase short class name is its default
namespace: `Attempt` becomes `attempt`, `HTTPAttempts` becomes `httpattempts`, and
`HTTP_Attempts` becomes `http_attempts`. Plurals and separators are preserved;
there is no word splitting or singularisation. Classes with the same short name
share a namespace unless an explicit override distinguishes them.

Use `#[Identity('stable-name')]` on a concrete model to preserve published URNs
across a PHP class rename. Overrides are not inherited; a subclass defaults to its
own concrete name. Namespaces must match `[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*`.
Noncanonical spellings are rejected rather than silently rewritten. Class names
that do not fit this ASCII grammar need an override.

`Identifier::create(ModelClass::class)` and `fromUuid(ModelClass::class, $uuid)`
derive the model namespace. `parse($urn)` retains any valid namespace without
looking up a class; `new Identifier($namespace, $uuid)` also needs no PHP model.
`uuidFor(ModelClass::class)`, `parse($urn, ModelClass::class)` and `Model::get($id)`
reject mismatched namespaces. Existing `item`, `asset`, `vault`, `assignment`,
`attempt`, `demand` and `checkpoint` URNs retain their exact public spelling.

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

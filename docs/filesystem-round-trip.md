# Filesystem configuration and manual discovery

Filesystem is a first-party, in-process Core integration. The first two increments
configure Inputs/Broadcasts and manually discover files. Acquisition and
publication are not implemented yet.
The [Tempest alignment note](filesystem-tempest-decisions.md) records the PR1
retrospective, installed APIs and narrow exceptions retained after review.
The lifecycle work follows [issue #28](https://github.com/Lost-and-Fonds/stashd/issues/28).

## Configure through the CLI

Use the normal Lerd shell (`lerd shell`) and Tempest CLI. Provision a PostgreSQL
database and an unprivileged application owner first. Supply these environment
settings through the normal application environment/secret provider:

```text
STASHD_OPERATIONS_DSN=pgsql:host=lerd-postgres;port=5432;dbname=<application database>
STASHD_OPERATIONS_USERNAME=<application owner>
STASHD_OPERATIONS_PASSWORD=<secret>
STASHD_OPERATIONS_SCHEMA=stashd_operations
```

The database must already exist and the owner must be able to create the private
schema. No default connection or administrative credentials are assumed. Keep
this schema separate from any schema supplied to `ArchiveProjection::rebuild`.
Initialization is explicit and repeatable; it preserves existing configuration.
It does not initialize the Vault. Existing archival CLI commands still need no SQL.

```sh
php tempest integration:init
php tempest input:create filesystem /absolute/directory-A
php tempest input:inspect <input UUID>
php tempest broadcast:create filesystem /absolute/directory-B
php tempest broadcast:inspect <broadcast UUID>
```

Create/inspect commands print JSON containing `id`, `kind`, `component` and
component-specific `settings`. Filesystem settings contain the canonical `root`;
there is no generic destination-root column. `filesystem` is the only registered
component. No external plugin package/runtime is involved. Invalid configuration
exits nonzero. No command creates source, destination or Vault directories.

A must exist and be a readable/searchable directory. B and the configured local
Vault may have missing directory suffixes. Roots must be absolute and cannot
traverse symlinks (including dangling links), files, `.` or `..` components.
All configured roots and the Vault must be pairwise disjoint: equality or
ancestry is rejected; a shared string prefix alone is allowed. Repeating the
same kind/root returns its existing UUID, including after restarting the CLI.
PostgreSQL transaction advisory locking serializes checks/insertion and schema
bootstrap across cooperating writers. Inspection reads saved configuration even
when A is offline; it does not authorize filesystem execution.

These checks assume operator-controlled local paths and cannot freeze directory
entries. Discovery rechecks confinement at use; acquisition/publication must do
the same when added. B will
be copy-only, with byte-identical skip and differing-file refusal; publication
is not implemented here.

## Discover through the CLI

Run `integration:init` again to add discovery tables to an existing operational
schema, preserving configuration and prior observations. Then:

```sh
php tempest input:discover <input UUID>
php tempest input:discovery <run UUID>
php tempest input:discoveries <run UUID>
php tempest input:discoveries <run UUID> <next cursor>
```

`input:discover` recursively enumerates every eligible regular file, including
binary, media, document, hidden and zero-byte files. It returns compact JSON run
status/counts and coverage. `finished` means the enumeration ended coherently;
inspect `coverage.kind` for `exhaustive` or `partial`. Neither means preserved.
The inspection command returns at most 100 observations and an opaque `next`
cursor; it never assembles the collection into one CLI response.

Filesystem implements the `InputDiscovery` port directly. Its host callback uses
the canonical plugin-api bounded-batch/progress semantics without RPC or SDK
dependencies. Each batch atomically commits observations, source associations
and its continuation or terminal coverage. Core rejects oversized/malformed,
stale and post-terminal batches. The run fixes complete-enumeration intent and
configuration; efficient refresh/scheduling is deferred. PostgreSQL session locks
exclude simultaneous invocations for one Input and release on process death.

On failure the command exits nonzero, retaining earlier checkpoints and an
operational error. Repeat `input:discover` for the same Input to resume its
unfinished run in a fresh process. After terminal completion the same command
starts a new complete scan. Inspections work with the source offline.

Continuation contains a versioned stack of directory-entry positions, plus a
bounded coverage summary. It requires no mutable process state or collection-sized
filename list; batches contain at most 100 Items and checkpoint after at most 100
visited entries. Resuming re-reads each active directory up to its saved position.
Directory mutation can reorder entries, so concurrent changes
may cause overlaps or omissions; this is not a point-in-time snapshot. Run a new
complete scan after changing the tree. Symlinks, special files, unsupported names
and unavailable subdirectories are excluded with partial coverage: a count and
first diagnostic, not an exhaustive error log. Known gaps survive checkpoints.
Unavailable roots fail resumably instead of claiming complete enumeration.

Filesystem's discovered ID is SHA-256 of the exact supported UTF-8 relative path
bytes, scoped by configured Input UUID. This hashes the locator, not file content
or Item identity. References and `urn:stashd:filesystem:source:1` metadata retain
the root/path needed by later independent acquisition; Core treats them as opaque.
Rescans update the same association and retain per-run observations; missing paths
never delete associations. New paths create associations. Every regular file is
enumerated again: size/mtime are observations, not an unchanged-content decision.
Acquisition must still snapshot/hash bytes and compare canonical evidence.

## Identity recovery requirement for the acquisition increment

Configuration/discovery SQL is operational state, not archival identity evidence.
Neither increment mints Items or claims recovery of preserved source associations.
The acquisition PR must store namespaced `stashd.filesystem.source` provenance
in each canonical Item revision: schema version, configured Input UUID, exact
relative path and observed source root. The minted Item URI remains the existing
Item record's `uri`; source locations do not become that URI.

Before acquisition may mint a URI following operational SQL loss, a narrow
reassociation step must verify canonical Item history and reconstruct
`(Input UUID, relative path) -> Item URI` from that provenance. Restore the
configured Input under its recorded UUID (operator confirms the current root);
do not silently create a replacement Input ID. Repeated identical associations
collapse; contradictory associations fail closed for that path, without merging
Items or rewriting Vault records. Discovery-only, never-preserved rows have no
archival identity to reconstruct. Restore configuration from an operational
backup until that step exists. A focused SQL-loss/retry proof is required in the
acquisition PR before claiming recoverability; there is no general recovery
subsystem in these increments.

## Focused proof

`./bin/test.sh tests/Acceptance/Integration` runs real
fresh-process CLI commands against generated, unprivileged PostgreSQL databases.
It reuses the existing projection fixture's explicit private test-admin setup
described in `docs/preservation/recoverable-transactions-phase2.md`; never point
the fixture at application data. It checks persisted rows independently, source
and Vault byte stability, absent destination creation, retries, safety rejection
and simultaneous callers waiting on the actual SQL authority. Discovery also
checks recursive bounded enumeration, additions/modifications/missing paths,
partial coverage, use-time root rejection and an injected terminal SQL failure.
A real CLI is SIGKILLed while its second batch is blocked in PostgreSQL: only
the first checkpoint survives, and a fresh CLI resumes without re-enumerating it.
The fixture injects SQL faults only; no internal Stashd service is mocked. Cleanup
removes only the generated database and role.

Configuration increment verification (23 October 2026): focused wrapper **4 passed, 159 assertions**;
full `composer qa` **362 passed, 33 existing incomplete, 20,316 assertions**,
with formatting/lint/analysis passing. Temporarily bypassing insertion, overlap
checks and configuration authority each made its CLI proof fail; source was
restored before final verification. All 27 new named declarations passed a
token-based multiline PHPDoc check. These are local results, not CI certification.

Post-review discovery verification (24 October 2026): focused CLI wrapper **10
passed, 1,691 assertions**; typed validation/cursor wrapper **3 passed, 47
assertions**. Full `composer qa` **371 passed, 33 existing incomplete**;
formatting/lint/analysis passed. Negative controls for lost continuation,
nontransactional adoption, false exhaustive coverage, missing nullable fields
and dotted-field aliases failed their relevant proofs. All mutations were
restored. A token-based check covered all 92 named declarations in the affected
Integration code, migrations, tests and helpers. These are local results, not CI
certification. See the linked Tempest alignment note for installed APIs,
remaining exceptions and revisit conditions.

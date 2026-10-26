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
inspect `coverage.kind` for `exhaustive` (no known skips) or `partial` (some entries
were skipped). Neither means preserved.
The inspection command returns at most 100 observations and an opaque `next`
cursor; it never assembles the collection into one CLI response.

Filesystem implements the `InputDiscovery` port directly. Its host callback uses
the canonical plugin-api bounded-batch/progress semantics without RPC or SDK
dependencies. Transfer records live in `app/Integration/DTO/`; the adapter and
Core services use the same `App\Integration\DTO` types. Each batch saves the found
files, source locations and next position or finished result in one transaction.
Core rejects oversized or malformed batches, outdated scan positions, and batches
received after the run finishes. The run keeps its original configuration and
complete-scan intent; efficient refresh and scheduling are deferred. PostgreSQL
session locks prevent simultaneous scans of one Input and release on process death.

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

## Acquisition

After `vault:init` and `integration:init`, run `input:acquire <discovery-run-id>`.
Retry that same command after interruption. `input:acquisitions <run-id> [after]`
returns at most 100 persisted attempts and the next cursor. Acquisition is serial,
uses the Input's discovery lock, and saves the transaction UUID before staging.
Only verified Vault completion establishes preservation; failed source reads retain
the same attempt for retry. Finish an earlier failed run before acquiring another.

Each regular file receives a minted Item URI and an `original` Asset. Host-owned
private staging is streamed, hashed and checked against a second source read and
opened/path inode evidence. Access-time changes are ignored. Sources must remain
operator-controlled and quiescent; these checks are not an atomic snapshot or
protection against hostile concurrent writers. Normal exits discard invocation
staging. Abrupt death can leave an unadopted private system temporary directory.

An unchanged rescan compares SHA-512, size and namespaced preservation metadata
against the verified exact current Asset pin. Equal files add no archival revision;
changed files retain identity and create new exact versions. Old acquisition runs
replay their saved result rather than reacquiring current source bytes. Missing
paths do not remove history. Broadcast execution remains a later increment.

## Identity recovery requirement for the acquisition increment

Configuration/discovery SQL is operational state, not archival identity evidence.
Configuration and discovery do not mint Items. Acquisition stores namespaced
`stashd.filesystem.source` provenance
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

DTO/readability review verification (24 October 2026): the combined CLI and
typed-validation wrapper passed **13 tests, 1,738 assertions**. Restoring the old
`DiscoveredItem` namespace in a collection annotation made the real manual-scan
CLI test fail with a missing-class error; the correct annotation was restored.
Full `composer qa` passed with Mago **1.57.0** and the inherited profile: **371
passed, 33 existing incomplete, 21,889 assertions**. The test stage used the
canonical wrapper, invoked with `sh` because the Lerd container has no `bash`;
the temporary Composer routing was removed afterward. An AST-based check covered
all **92 declarations** with no multiline PHPDoc failures. Tempest's partial
discovery cache regenerated successfully. Unrelated Mago return-spacing changes
are isolated in their own commit; the two temporary lint exceptions and style
limits are recorded in the linked alignment note. These are local results.

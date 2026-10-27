# Filesystem integration

Filesystem is a first-party, in-process Core integration. Configuration, discovery,
acquisition and Vault-only Filesystem Broadcast publication are implemented.
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
exits nonzero. Configuration commands never create source, destination or Vault directories.

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
entries. Discovery, acquisition and publication recheck confinement at use.
B is copy-only, with byte-identical skip and differing-file refusal.

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
Successful completion and replay release redundant sealed custody through the
transaction engine's verified release API before acknowledging operational success.
Canonical versions and retained journals remain readable without source or custody.

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
paths do not remove history. Publication does not require the source to remain online.

## Broadcast execution seam

Run `integration:init` again to install the publication tables. Freeze a selection
through `php tempest broadcast:select <broadcast UUID> <selection.jsonl>`. Each
line is an object with string `item_id` (the canonical Item Object URN) and explicit
`version` (for example `v1`). JSON lines allow a large selection to be read without
loading the whole file. Duplicate Item identities and invalid or missing revisions
reject the entire selection. Empty selections are valid.

`broadcast:publication <run UUID> [after-position]` exposes status and at most 100
committed reports. `broadcast:build <run UUID>` executes that existing selection
through `BroadcastCoordinator::execute` and `FilesystemPublisher`. Failure exits
nonzero and saves an operational diagnostic. Retry the same UUID; completed runs
return without touching destination files. Create another selection/run to publish again.

Selection verifies explicit Item revisions and their exact Asset pins. This slice
accepts one byte representation per Asset; multi-file Asset representations fail
explicitly. Execution never substitutes current heads. Collection reads deliver at
most 100 Items, grant opaque Asset references only when delivered, and require a
final EOF read. Streams enforce requested byte ranges and are closed/revoked when
the invocation ends. No Vault filesystem path or source access is passed to the
publisher. The in-process adapter is trusted application code, not a sandbox.

Published relative names must be valid UTF-8 and satisfy Core's relative-name
shape checks. This value does not authorize local filesystem access. The canonical
publication contract does not universally prohibit control characters; the
Filesystem adapter must additionally call `FilesystemRoots::relative()` before
using or reporting a local path, including its stricter control-character policy.
These policies intentionally remain separate rather than coupling every destination
to a local filesystem validator. Publishers return the typed `FileReportStatus` enum.

File and destination-metadata reports are provisional bounded batches. Duplicate
file paths reject the entire batch; successful corrected batches may follow.
Only a valid successful lifecycle response with EOF and consistent file status
exposes reports. Exceptions discard them. A killed process can leave hidden
provisional SQL rows and `running` status; retry under the destination lock clears
those rows and replays the same selection with fresh grants. Completed runs are
idempotent; create a new run to deliberately publish again. Destination side effects
are not rolled back by this seam.

Tempest supplies discovered migrations, ordinary queries, transactions, console
commands and dependency injection. Session advisory locks follow the existing
PostgreSQL discovery pattern because a publisher must serialize across processes
without holding a database transaction for the whole publication. Native stream
reads/seeks retain verified `AssetArchive` handles and enforce ranges without
exposing those handles to destinations. No external runtime, RPC, SDK, staged-output
adoption, watcher, pruning or hardlinks are introduced. Selection/report rows are
operational state and do not establish preservation or survive SQL loss by design.

## Publish the round trip

```sh
php tempest vault:init
php tempest integration:init
php tempest input:create filesystem /absolute/directory-A
php tempest input:discover <input UUID>
php tempest input:acquire <discovery-run UUID>
php tempest input:acquisitions <discovery-run UUID>
php tempest broadcast:create filesystem /absolute/directory-B
php tempest broadcast:select <broadcast UUID> selection.jsonl
php tempest broadcast:build <publication-run UUID>
php tempest broadcast:publication <publication-run UUID>
```

Populate selection JSON lines with the exact preserved Item `object_id` and
`version` returned by acquisition, as `item_id` and `version`. Preservation and
selection complete before publication; A can be removed by the operator/test
harness at that point. Stashd never removes or moves original source files.
Historical revisions reconstruct into separate fresh destinations when their
bytes differ. Titles, media facets and internal `data.bin` names are not output names.

The publisher requires complete version-1 `stashd.filesystem.source` provenance
and one selected Asset. It preserves the canonical revision's exact supported
relative path, including Unicode and spaces. Missing/invalid provenance fails.
It drains bounded collection batches to explicit EOF before writing payloads,
using a disk-backed plan and case-folded prefix index to reject duplicate names,
file/directory conflicts and case aliases. Grants are invocation-authenticated
references into an automatically removed temporary stream, not an in-memory
collection-sized map. Temporary disk usage scales with the selection and one file.

Each file is copied solely through `openAsset()` into a private destination-local
workspace. SHA-512 and byte count of the verified stream must match a second read
of staging. Existing regular files are hashed and matching files are skipped;
differing files, symlinks, special entries and unsafe parents fail. Unrelated
files stay untouched. Same-device checks reject nested mount destinations rather
than permit PHP's cross-device rename fallback. Rename exposes only complete
files; the resulting inode and size are checked before reporting success.

The existing destination lock serializes cooperating Stashd invocations. Roots
and components are rechecked at use, allowing missing Input suffixes but never
root overlap. This is not protection against hostile external directory/rename
races: operators must exclusively manage B during publication. Atomicity is per
file, not per tree. No power-loss durability, filesystem metadata/ACL fidelity,
empty-directory preservation or atomic no-replace guarantee is claimed.

Failure can leave complete files but no successful invocation reports. Retry
verifies/skips matching files from the same frozen selection and publishes the
rest. Normal exit removes only its private workspace. SIGKILL may leave a hidden
`.stashd-publication-*` workspace; after confirming its process is dead, operators
may remove that specific workspace. Retry never broadly cleans/prunes B. Lost
acknowledgement after rename safely reuses complete files.

Tempest's installed Storage/GenericStorage and Filesystem helpers were examined:
`move` offers no atomic/no-replace contract, checksum does not select SHA-512,
and its temporary-directory helper is not destination-local. Narrow native
exclusive stream creation, hashing, device/inode checks and rename are used for
these requirements; real CLI/Vault/PostgreSQL tests cover them. Revisit if Tempest
adds equivalent verified local publication primitives. No dependency is added.

Deferred: watchers, scheduling, templates, external runtimes/RPC/SDK/OCI,
hardlinks/reflinks, pruning/sync, rename inference, remote storage and per-Item
partial success. Reports retain the 0.19 whole-invocation provisional contract.

## Publication verification

Local PR5 verification: complete canonical wrapper **417 passed, 33 existing
incomplete, 23,245 assertions**; full `composer qa` **417 passed, 33 existing
incomplete, 23,249 assertions**. Formatting and lint pass; analysis retains three
pre-existing informational suggestions. Incomplete contracts cover broader fixity,
replica, metadata, evidence, concurrency and catalogue capabilities, not passes.

The 13 publication cases use real fresh Tempest processes, generated unprivileged
PostgreSQL fixtures, physical Item/pin inspection and released-custody checks.
They reconstruct binary/document/media/nested/Unicode/spaced/empty files after
source removal, publish v1 and v2 separately, retain completed-run inode identity,
verify export isolation, and reject unsafe paths, differing bytes, duplicate/case/
structural collisions and missing/corrupt payloads. SIGSTOP/SIGKILL during an
observed partial staging write leaves no final filename; a SQL test-only gate
also permits SIGKILL after rename before report acknowledgement. Fresh retries
succeed, and the latter reuses the existing complete inode.

A temporary negative control forcing all Asset reads to v2 made the historical
proof fail (unchanged Assets correctly have no v2); restoring exact pinned reads
passed again. This is not a complete latest-head substitution mutation proof.
Invalid-provenance/collision cases mutate disposable frozen SQL records to exercise
publisher validation; they do not establish new canonical provenance authority.
No million-Item performance, hostile-writer safety or power-loss test is claimed.

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

Reconciliation uses intact sealed candidate provenance to recover only this Input's
transactions. Unrelated sealed work is not advanced; unrelated damaged journals
and Items are isolated. Relevant journal failures and conflicting canonical claims
stop acquisition, and recovered associations are saved in one SQL transaction.
Unreadable candidate seals cannot establish ownership. If both canonical provenance
and operational identity records are lost or unreadable, restore known evidence
before acquisition; automatic recovery cannot infer the missing source identity.

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

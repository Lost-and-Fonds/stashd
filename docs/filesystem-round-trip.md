# Filesystem configuration (round trip PR1)

Filesystem is a first-party, in-process Core integration. This increment only
configures Inputs and Broadcasts: no scanning, acquisition or publication yet.
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
entries. Discovery/acquisition/publication must recheck confinement at use. B will
be copy-only, with byte-identical skip and differing-file refusal; publication
is not implemented here. No lifecycle interfaces or future job tables are
prebuilt in this increment.

## Identity recovery requirement for the acquisition increment

Configuration SQL is operational state, not archival identity evidence. PR1
does not mint Items and does not claim recovery of preserved source associations.
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
subsystem in PR1.

## Focused proof

`./bin/test.sh tests/Acceptance/Integration/ConfigurationTest.php` runs real
fresh-process CLI commands against generated, unprivileged PostgreSQL databases.
It reuses the existing projection fixture's explicit private test-admin setup
described in `docs/preservation/recoverable-transactions-phase2.md`; never point
the fixture at application data. It checks persisted rows independently, source
and Vault byte stability, absent destination creation, retries, safety rejection
and simultaneous callers waiting on the actual SQL authority. Fixture cleanup
removes only the generated database and role.

Local verification (23 October 2026): focused wrapper **4 passed, 159 assertions**;
full `composer qa` **362 passed, 33 existing incomplete, 20,316 assertions**,
with formatting/lint/analysis passing. Temporarily bypassing insertion, overlap
checks and configuration authority each made its CLI proof fail; source was
restored before final verification. All 27 new named declarations passed a
token-based multiline PHPDoc check. These are local results, not CI certification.

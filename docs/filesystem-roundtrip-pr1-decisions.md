# Filesystem round trip PR 1

This slice connects existing Integration work to Catalogue and Operations. The
acceptance contracts from #86 keep their command names, response fields and
observable assertions. Mago reformats them without changing their assertions.

## Persistence and authority

`Stash`, `Item`, `StashInput`, `StashItem` and `ItemSource` extend
`App\Persistence\Model`. Collection membership has foreign keys to collections,
Items and existing Input configurations. Item stores only the canonical URI;
Object identifiers are derived by the existing ItemArchive. Revisions and exact
Asset pins remain solely authoritative in the canonical Vault.

ItemSource maps the existing `acquisition_sources` table rather than establishing
another source identity map. Its `(input_id, source_id)` relates to DiscoveryStore's
source associations. It deliberately has no foreign key requiring a current
discovery association: existing recovery can restore identities from canonical
provenance before rescanning a rebuilt operational database. Conflict checks in
AcquisitionStore and AcquisitionCoordinator remain in place.

The additive migration backfills Items from existing source URIs and adds model
UUIDs and timestamps to the existing mapping. It leaves Input identifiers,
source identifiers, transaction IDs and archival bytes unchanged. A populated
upgrade proof reconstructs the old disposable schema, upgrades it, and checks
those saved facts before recovering acquisition without its source.

The installed Tempest database/model, migration, mapper, console and DI facilities
are used. The narrow SQL exceptions are PostgreSQL's set-based migration backfill,
adding generated UUID/default columns to the existing composite-key table, and
`SELECT ... FOR UPDATE` for concurrent membership inserts. The installed select
builder has no row-lock method. These statements stay in the migration and a
small parameterized method; there is no generic repository or second connection.
Revisit these exceptions if Tempest exposes the required operations directly.

## Execution and recovery

Three scalar Jobs retain saved run UUIDs and use `Worker::do`, existing Messenger
outbox delivery and Durable Activities. The existing coordinators perform the
actual work. Only `Result::record` accepts successful coverage, under the existing
Operations authority fence. Admission receipts never claim completion.

Discovery saves its run before admission; failed admission leaves replayable
state. Its Job resumes that exact run and links stable acquisition identities to
Stash membership. PR 1 explicitly rejects multi-file acquisition through this
new Operations command. Bounded directory admission remains PR 2.

Acquisition uses the existing persisted attempt's PreservationTransaction ID.
Recovery verifies canonical evidence before opening source files. The transaction
journal and release receipt survive removal of redundant custody bytes. The #86
PostgreSQL barrier proves actual physical Item/Asset v1 publication before Result
acceptance, then removes the source and kills the real worker. Recovery accepts
one result for the same Assignment and creates no additional OCFL versions.

Production transport visibility is shortened from 60 to one second so a killed
worker recovers within the acceptance window. PostgreSQL session claims still
exclude a healthy owner from duplicate execution. This can increase delivery
polling for long-running work; it does not grant exactly-once arbitrary effects.
The existing healthy-owner and takeover contracts remain required regressions.

## CLI naming for review

`input:discover`, `input:acquire` and `broadcast:build` now return admission
identities as proposed in #86. Explicit synchronous diagnostic commands are
`input:scan`, `input:preserve` and `broadcast:publish`; existing lower-level
regressions use those names and retain their assertions. Configuration and
read-only inspection commands keep their original contracts. This execution CLI
change is intentional and needs review before merge; no acceptance receipt was
silently changed to fit the implementation.

`stash:broadcast:select` resolves current real membership to verified exact Vault
revisions. The existing Broadcast coordinator freezes the pins before admission;
publication uses only those frozen versions and canonical bytes.

## Evidence and limits

The external Python Microtext verifier supplies the expected answer. Neither the
application nor the test copies Input bytes to the output. Physical OCFL checks
are independent of Operations status. The deliberate false-completion control
replaced acquisition with a fabricated successful count: the real acceptance
test failed because the physical OCFL inventory was missing. The implementation
was restored afterward. The acceptance test also detects deliberately corrupted
Vault payload bytes after Operations reports completion.

This is the single-file slice. No watcher, external plugin runtime, broad metadata
system, hardlinks, pruning, directory admission cursor, mutation policy or 10,000
Item stress integration is added. Copy-only publication retains the existing
per-file atomicity and operator-controlled-root assumptions. Whole-tree atomicity,
hostile concurrent filesystem writers, power loss, publication interruption and
the wider stale-worker matrix are not established by PR 1.

## Verification performed

In the intended Lerd PHP 8.5 environment with disposable PostgreSQL databases:

- Canonical `./bin/test.sh` for both #86 contracts and the populated upgrade:
  **3 passed, 494 assertions**.
- Focused discovery and acquisition regressions through the same wrapper:
  **11 passed, 1,760 assertions**.
- Full `lerd composer qa`: **574 passed, 42 existing incomplete contracts,
  zero failures**, exit 0. Mago formatting/lint passed; analysis emitted four
  existing informational notices and no errors.
- Independent Python fixture regression suite: **8 passed**.
- Fabricated acquisition completion: acceptance failed at the missing physical
  OCFL inventory; real implementation restored and the focused proof passed.
- The protected corruption control and actual post-commit SIGKILL/recovery proof
  ran successfully as part of the acceptance suite.

Local setup required Python 3 in the Lerd image and the documented private
projection test-admin file. Lerd debug capture was disabled during tests because
its collector prints a PHP 8.5 `ReflectionProperty::setAccessible` deprecation
into JSON CLI output. No application assertion was relaxed to hide it.

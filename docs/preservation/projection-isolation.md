# Vault projection recovery isolation (#60)

`ArchiveProjection::rebuild(PDO, schema)` returns `{items, incidents}`. `items`
contains exact `{object_id, version}` references for every completely verified
Item. This replaces the previous bare list return; the only in-repository caller,
the transaction test adapter, forwards the report.

Rebuild holds the existing exclusive publication authority for discovery,
verification and SQL replacement. The Vault remains authoritative. No rebuild
operation repairs, promotes, deletes or rewrites archival bytes, journals or custody.
SQL rows and previous incidents never establish archival evidence.

## Degradation boundary

One failed Item inspection, revision read, pinned Asset inspection, missing pin or
ownership check excludes **all versions and pins of that Item**, even versions
already verified earlier in the scan. Healthy Items continue independently.
Inspection conservatively checks the whole Object's retained publication/content:
a damaged unpinned version of an otherwise pinned Asset also degrades its Item.
No claim of partial Object validity is made.

Dependencies are checked for each Item revision. A shared exact Asset pin is
retained in every verified revision that references it; failure excludes every
dependent Item, never unrelated Items. Current Asset identity binds it to one Item
URI, so a pin forged across Item identities fails ownership verification. No
Asset-global SQL rows are deleted on behalf of a degraded Item.

Discovery walks physical Object roots without opening them through OCFL's
fail-fast `listObjects()`. Root and historical inventory IDs are only discovery
hints, accepted when the layout resolves back to the observed path. They cannot
authorize rows. If no such ID can be read, an incident identifies the physical
Vault-relative path with a null Object ID. Existing rows for undiscoverable or
absent Items are removed by full replacement. Roots missing both their Object
marker and all root inventory evidence cannot be identified as Objects; they
produce no rows. Root identity/layout/listing failures abort before SQL changes.
Discovery distinguishes unavailable storage from observed missing or malformed
evidence: exceptions from directory listing, existence checks, file reads or
layout resolution abort the scan and leave all SQL rows untouched. A confirmed
absent inventory file is skipped; readable malformed JSON supplies no identity
hint, so discovery tries historical candidates or reports the unidentified root.
A file disappearing between its existence check and read aborts conservatively.
Infrastructure exceptions are never evidence of corrupt inventory bytes.
The same rule applies during publication/content verification: the OCFL adapter
marks provider observations with `ArchiveReadFailure`. Inspection shares the first
failure with its validation view and rethrows it even if the OCFL library catches
or wraps it as a validation issue. Projection lets that failure abort replacement.
Confirmed missing paths and successfully read bytes with invalid checksums or
formats remain corruption findings and continue to isolate the affected Item.
Extension directories are not scanned as Objects.

## Incident and SQL contract

The return report and `<schema>.incidents` contain the same fields:

| Field | Meaning |
| --- | --- |
| `object_id` | Discovery Item ID, or null for an unidentified Object root |
| `path` | Observed Vault-relative Object root; primary key |
| `item_version` | Revision being checked, or null for Object-wide/discovery failure |
| `dependency_object_id` | Failing pinned Asset ID, or null |
| `dependency_version` | Exact failing pin version, or null |
| `reason` | Human-readable failure diagnostic, not a stable machine error code |

One incident records the first failure encountered per affected Item/root. It does
not claim an exhaustive damage inventory. An Object-wide inspection failure has
no specific failing version. Incidents are disposable current-scan diagnostics,
not an append-only preservation ledger. After external repair, a successful scan
replaces them with verified rows; rebuild itself never performs that repair.

One PostgreSQL transaction creates missing tables and replaces `items`, `pins`
and `incidents` together. This removes stale healthy/degraded/absent rows and stale
incidents. A SQL error rolls back the entire replacement; the previous complete
projection remains. Callers supply an exception-mode PDO, no active transaction,
and a private schema. Connection, root discovery, process death and SQL failures
still propagate rather than being reported as successful degradation. There is
no new power-loss, device-durability or distributed-fencing guarantee.

## Proof

`ProjectionIsolationTest.php` enters through the existing fresh-process transaction
adapter, publishes real multi-revision Items/Assets, and invokes production
rebuild against generated unprivileged disposable PostgreSQL databases. It queries
exact versions/pins and incidents directly, tests stale replacement, schema loss,
repeated scans, repair reappearance and forced SQL rollback, and compares complete
physical Vault snapshots. Cases damage Item payloads, root inventories, pinned
Asset payloads/declarations and every Item inventory identity hint. Two healthy
revisions share an exact historical Asset pin. Only fixture bytes are damaged.

The fixture configuration and invocation use the existing instructions in
`recoverable-transactions-phase2.md`. No new test adapter or preservation engine
is introduced. Existing transaction/crash contracts remain unchanged.

Local validation on 2026-10-23: canonical complete suite **356 passed, 33 existing
incomplete, 19,947 assertions**; `composer qa` also passed (three unrelated
analysis help messages). Focused changed-file analysis reported no issues. Final
serial isolation plus independent PostgreSQL witness run: **6 passed, 1,016
assertions**. Disabling the per-Item exception boundary caused four damage cases
to fail; restoring fail-fast OCFL listing caused the unidentified-inventory case
to fail with E033. Both mutations were restored before the final successful run.
Generated databases/roles and private test-admin configurations were removed.
These are local execution results, not independent external certification.

PR #67 review correction: discovery now propagates storage I/O exceptions rather
than treating unavailable reads as malformed evidence. The targeted real-PostgreSQL
regression failed against the previous implementation (the expected read failure
was swallowed), then passed with the fix: existing Item, pin and incident rows
and all Vault bytes survive the read fault. Its malformed-JSON control still
isolates the unreadable identity hints and restores the unrelated healthy Item.
Focused isolation suite: **6 passed, 1,019 assertions**. Required `composer qa`:
**357 passed, 33 existing incomplete, 20,053 assertions**, exit 0. Changed-file
analysis found no issues. Generated fixture databases/roles and private test-admin
configuration were removed again.

The next review added the same fault at `inventory.json.sha512`, after discovery
has succeeded. It reproduced silent degraded replacement on `6ab752c`, then passed
with typed provider failures retained across the OCFL validation views. The same
test independently checks all three populated SQL tables and the physical Vault
remain unchanged on I/O failure; a readable malformed sidecar still excludes only
its affected Item. Focused isolation suite: **7 passed, 1,109 assertions**. Final
`composer qa`: **358 passed, 33 existing incomplete, 20,058 assertions**, exit 0;
formatting, lint and analysis passed. Generated fixture databases/roles and private
test credentials were removed. No independent external execution is claimed.

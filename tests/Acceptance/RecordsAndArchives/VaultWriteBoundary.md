# Vault object-write boundary evidence

## Scope

Based on the 2026-10-14 Core vNext Architecture Decisions and the Preservation
and Acquisition reconciliation in `hipsterjazzbo/obsidian-vault`, not legacy issues.
This change protects Vault object writes; it does not implement Item/Asset
preservation, custody checkpoints, plugin contracts, or a commit journal.

Logical paths are rejected rather than normalized: absolute/drive paths,
backslashes, control characters, empty components, `.` and `..` components.
The complete file map is validated before object creation or staging. Missing
absolute acquisition sources are rejected rather than reinterpreted as paths
inside archive storage. The OCFL adapter rejects source symlinks, including
ancestor components, before hashing and again before copying.

## Reproducible evidence

Run through the configured PHP 8.5 container with `lerd composer` (host PHP's
intl extension is disabled by default).

- Before fixes: the new boundary suite had 31 failures, 2 warnings and 5 passes.
  Unsafe paths could commit successfully; ancestor symlinks were accepted;
  missing absolute sources were incorrectly looked up inside the Vault.
- `lerd composer test -- tests/Acceptance/RecordsAndArchives/VaultWriteBoundaryTest.php tests/Acceptance/RecordsAndArchives/VaultObjectTest.php --compact`:
  **44 passed, 243 assertions**. Real Tempest storage and OCFL operations;
  filesystem snapshots, inventory/sidecar checks, historical payloads, rejected
  first-create retry at v1 and rejected-update retry at v2. The existing 32 MiB
  streaming test now checks both ingestion and historical reading with less
  than 2 MiB additional PHP memory each.
- Focused `vendor/bin/mago lint` and `vendor/bin/mago fmt --check` on the two
  production files and two PHP test files: passed. `git diff --check`: passed.
- `lerd composer test -- --compact`: **3 failed, 49 incomplete, 58 passed**
  (287 assertions on the final full-suite run).
  Failures are existing AssetPreservation traversal/missing-source and
  CommitConsistency interruption rejection expectations encountering the
  explicitly unimplemented driver operations. These contracts are unchanged.
- `lerd composer qa`: stopped at the existing `no-unsafe-finally` lint error in
  `ObjectWriteLockTest.php`. Formatter-only churn outside this change was removed.
- Static analysis was run globally and on changed paths; it is **not green**.
  Focused production analysis reports seven errors, also reproduced against
  `origin/dev`: untyped storage-list entries, generic lock callback return types,
  and file-map argument types. Pest closure binding/receipt inference also
  produces analysis errors for acceptance tests. No suppressions were added.

## Precise upstream limitations, not guarantees

The installed `ottosmops/ocfl` VersionBuilder does not validate logical paths;
Vault validation protects only this boundary, not arbitrary direct library use.
`VersionBuilder::applyStagedChanges()` hashes sources before
`StagedContent::writeTo()` copies them. The Filesystem interface has no adopted
stream/digest receipt or source identity contract. Checking symlinks at hash and
copy time does not eliminate a concurrent source replacement race or prove that
the copied bytes match the earlier digest. This needs upstream support for
verified adoption, not a claim that path preflight solves mutation.

`VersionBuilder::materialise()` publishes the version directory, then root
inventory, then root sidecar separately. Failure after version publication can
leave an orphan version directory blocking retry; failure after inventory
replacement can leave an advanced head despite an exception. There is no
recovery/journal API here. This change does not delete version directories,
roll back inventories, fake success receipts, or claim generic crash-safe retry.
Only the tested pre-publication input rejections have safe retry evidence.

Fault injection, source-mutation, distributed fencing, remote backends,
independent full OCFL validation and power-loss/replica recovery proofs remain
incomplete. A failed initial acquisition may leave OCFL pending metadata and an
object marker; tests prove no committed inventory/version and safe same-ID retry,
not complete removal of every uncommitted artifact.

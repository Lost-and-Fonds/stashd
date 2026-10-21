# Local preservation transaction decision record

Status: Phase 2 implementation choices for review, not a distributed protocol.
Frozen independent baseline: `680476b57f87b5d356275074688c35eaadea4313` (#57).

The local Vault owns `extensions/stashd-transactions/`. Each SHA-256 transaction-ID
bucket retains an immutable JSON candidate, a SHA-512 seal and private copied
custody files. Preparation publishes a complete directory by sibling rename;
preparing directories are never candidates. Retain sealed candidate, plan,
completion and binding evidence indefinitely in this phase. Custody is temporary:
explicit `releaseCustody()` may reclaim it only after re-verifying persisted
authoritative completion. Unfinished candidates retain every accepted copy.
No SQL is needed to enumerate sealed candidates. Missing or damaged permanent
evidence or still-needed custody causes quarantine without changing the Vault.
The seal detects accidental damage, not a hostile administrator capable of rewriting both content and checksums.

All local Core Object writers and transaction committers share a non-expiring
flock on this Vault. It remains held through verification and completion; a live
owner cannot be timed out or replaced. Kernel process death releases authority.
Nested Object writes reuse the owning Vault instance's authority. This is local
process exclusion, not a distributed lease or power-loss guarantee. No committer
may bypass Vault publication or replace/delete its authority file.

Candidates contain explicit metadata field assignments, changed Asset files and
exact pin preconditions. Capture the current Item head at preparation and reject
any changed head before starting publication. This deliberately chooses explicit
conflict over disjoint stale merges for Phase 2. Unmentioned metadata and pins
survive; removals and observation ordering are deferred product decisions.

Before OCFL publication, persist a sealed commit plan under authority, including
exact predecessor and intended version identities, the complete resulting Item
snapshot and the candidate digest. Embed that candidate/plan binding in each
Asset record and Item record via the existing archive services. A crash after
publication but before a receipt is recovered by independently verifying OCFL,
reading the exact planned version and checking its binding and content. Intended
version numbers alone are never evidence. A completion file is only a replay
hint: every replay revalidates the exact Item and all its pinned Assets.

Interrupted intra-Object material that existing publication inspection cannot
verify remains quarantined; no automatic orphan promotion or cleanup. A complete
acknowledged Object with no inspection issues can be reused. Known interrupted
writes requiring automatic repair need a separately reviewed evidence rule.

Projection rebuilding reads verified authoritative Item history into physical
PostgreSQL tables. It runs separately from commit and cannot authorize a new
archival revision. The acceptance fixture provisions only parent-generated,
strictly validated disposable databases/roles/schemas; test administration is
separate from unreachable ordinary application DB configuration. This initial
projection is deliberately all-or-nothing: damage in one unrelated Item or Asset
blocks restoring healthy rows. Per-Item recovery isolation is deferred and tracked
in [#60](https://github.com/Lost-and-Fonds/stashd/issues/60); it is not approved as
the final catalogue recovery behavior.

Review decisions still required: permanent-evidence retention, user-facing
conflict resolution and removal semantics, repair of interrupted OCFL writes,
production projection schema integration and distributed deployment. Local
process crashes only: no fsync, device-loss, remote backend or power-cut claim.
Supplementary tests and negative mutations require independent test-owner review
before #58 can be declared complete.

## Physical format and operation

`PreservationTransaction::prepare()` accepts one Item URI, Asset-key-to-file
changes, metadata field assignments and optional exact pin preconditions.
`commit()` is also the recovery entry point: dispatch/recovery provides only the
sealed identity, never a replacement snapshot. `discover()` reports healthy IDs
and damaged buckets separately so one incident cannot hide unrelated candidates.
Callers schedule discovery/recovery; no new worker or queue dependency is added.

Each bucket is `sha256(transaction_id)`. `candidate.json` contains format
`stashd-transaction-1`, Vault URN, observed Item base and the explicit delta.
`candidate.sha512` hashes its exact bytes. Custody names are ordinal, assigned
in sorted Asset-key order. Producer source paths are not retained as authority.
`plan.json` and `complete.json` are immutable envelopes with `json` (the original
JSON string) and `sha512` (its digest). Write to a unique sibling temporary file,
then rename under authority. A leftover temporary file is never a published plan.
A missing plan cannot be reconstructed from a revision that already names this
transaction. Failed and interrupted publication evidence is retained. Explicitly
authorized completed-custody release affects only the candidate-named copies,
never journal envelopes, transaction bindings or OCFL files.

Each changed Asset's `.stashd/asset.json` and the Item's `item.json` carry
`transaction: {id, candidate, plan}`. The candidate value is the exact candidate
SHA-512; plan is SHA-512 of the decoded plan re-encoded by PHP with
`JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION`. Plan intent contains the exact
predecessors and the complete resulting Item metadata/pins. Recovery verifies
that this is the sealed delta applied to the retained predecessor, checks each
exact-version binding and compares accepted Asset bytes. Unchanged pins are
independently verified too. Existing single-Object readers are unchanged in their
conservative interpretation of pending publication material.

Local authority is `extensions/stashd-transactions/authority.lock`, held with
`flock(LOCK_EX)`, with an actual nonblocking denied claim observed before waiting.
All local `Vault` Object publication entry points use it. There is no expiring
lease, ownership token or live-owner takeover path. The supplementary aged-file
test proves that an apparently stale lock file cannot revoke a still-live kernel
owner. It is not a distributed stale-token proof. Replacing the authority inode,
writing storage outside Core, multi-host filesystems and hostile administrators
are outside this phase's trust model.

`ArchiveProjection::rebuild()` verifies every exact historical Item and pinned
Asset before replacing SQL rows in one database transaction. The initial physical
`items` and `pins` tables describe all historical revisions. The test adapter only
constructs production services, maps operations and selects existing CrashStorage
instrumentation; the separate fixture owns disposable PostgreSQL administration.
Set private ignored `tmp/transaction-projection-admin.json` to the explicit local
PostgreSQL admin connection for T09. It is deliberately required rather than
falling back to application credentials. Remove it after the test. The test
bootstrap selects the adapter via an absolute path unless explicitly overridden.
Lerd does not forward arbitrary shell environment variables to Composer.

## Verification — 22 October 2026

The protected Phase 1 test, process harness, TransactionProbe, ProjectionProbe
and ProjectionProbeProofTest have no diff against the frozen full SHA above.
No assertion, expected status or protected hook has been amended.

| Check | Observed result |
| --- | --- |
| `STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php tests/Acceptance/RecordsAndArchives/TransactionEvidenceTest.php --compact` | **77 passed, 8,170 assertions**: all 48 frozen cases (35 formerly missing capabilities plus 13 controls) and 29 supplementary cases. |
| `STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives --compact` | **254 passed, 33 existing incomplete, 10,831 assertions**, before the final three supplementary cases and discovery/JSON refinements. Existing crash and historical retrieval cases passed. |
| `./bin/test.sh --compact` (final complete suite, four processes) | **261 passed, 33 existing incomplete, 10,950 assertions**; exit 0. |
| Focused Mago lint/format | Passed; two early-continue help messages in supplementary tests. |
| Focused Mago analysis of every changed PHP file | **0 errors, 6 warnings** for Pest's dynamically bound fixture property, plus one redundant-comparison help message. No suppressions added. |
| Token-based PHPDoc check of declarations in changed/new PHP files | **97 declarations** have multiline documentation; implementation contracts also manually inspected. |
| `composer qa` (twice) | Stops at unchanged `ObjectWriteLockTest.php:86` unsafe-finally lint error. |
| Separate `composer analyse` | Repository remains non-green: **1,163 errors, 181 warnings, 11 help messages**. Focused changed-file findings are distinguished above. |

Supplementary process tests kill real PHP children using all ten existing
CrashStorage boundaries for both Asset and Item publication. They verify the
SIGKILL and physical diagnostics, retain earlier immutable files, and require
quarantine without a single new Vault byte when pending material remains—even
after a root sidecar has been replaced. The original eleven transaction hooks
exercise recoverable, unambiguous boundaries with three fresh-process replays.
The independent reader checks raw manifest and envelope digests, exact-version
OCFL sidecars and each candidate/plan binding. Further vectors cover lost seals,
lost custody, malformed/missing/conflicting plans, damaged-bucket isolation,
non-expiring live-owner exclusion and numeric JSON type retention.

Four temporary production mutations were run sequentially with source restoration
in `finally` blocks. Every experiment exited 1 due to failed assertions:

- Trust saved completion without rereading Vault: T13 fails all three damaged
  committed-evidence cases (168 assertions across the failed cases).
- Republish an already-completed Asset step: T03 fails its settled-byte snapshot
  comparison (58 assertions).
- Replace retained Item pins with an empty snapshot: T08 rejects the candidate
  instead of completing (4 assertions).
- Omit the Asset's transaction binding: the physical-reader case rejects the
  incomplete outcome before reading a nonexistent Item (7 assertions).

A final read-only PostgreSQL check found **0 generated databases and 0 generated
roles** remaining. Private test-admin configuration was removed afterward.
No mutations, test-admin credentials, working Vault changes or generated fixture
roles/databases are deliverables. The PR must remain unmerged.

## Review gate and unresolved architecture

This is implementation evidence, not independent acceptance-owner approval.
Supplementary vectors and the physical format need that owner's review before
#58 can be called complete. The PR should remain draft until that gate and the
phase-specific decisions above are reviewed. In particular, confirm conservative
whole-Item stale conflict, indefinite permanent-evidence retention and quarantine
of interrupted single-Object material. Automatic repair is deliberately unresolved; this change
does not silently grant orphan-promotion authority. Production catalogue schema
adoption, removal semantics, acquisition/version deduplication, distributed
fencing and device durability require separate decisions and evidence.

## PR #59 review: completed custody lifecycle

Custody is required until `complete.json` has been persisted after verification.
`releaseCustody(id)` is an explicit maintenance operation: it refuses an unfinished
candidate with `not-complete` and never advances publication to make cleanup
possible. It holds the same non-expiring Vault authority as commit/recovery.
Before any deletion it invokes the production completion verifier, which checks
sealed candidate/plan evidence, exact OCFL transaction bindings, changed Asset
sizes/digests, the resulting Item metadata/pins and all retained pinned Assets.
A completion receipt alone cannot authorize deletion or reacknowledgement.

After that verification, cleanup preflights every remaining custody entry and
persists an immutable checksummed `custody-release.json` envelope containing the
verified completion receipt and candidate seal digest. It then unlinks only the
manifest-named `custody-N` regular files. The candidate, seal, plan, completion,
release authorization and all OCFL bytes remain unchanged. Unexpected, linked or
changed custody entries are retained for diagnosis. Unexplained missing copies
before initial authorization cause cleanup quarantine; missing copies after
recorded authorization are the normal interrupted-cleanup state.

Completed `commit`/`recover` verifies accepted byte evidence against authoritative
OCFL instead of requiring redundant copies. Interrupted cleanup cannot break
completion replay, including after only one of several files was removed. Every
cleanup retry re-verifies OCFL and the original release authorization before
removing remaining copies; there is no unchecked sweeper. A damaged authorization
blocks further cleanup but does not independently invalidate intact archival
completion. Missing/corrupt permanent publication evidence still quarantines
replay and cleanup. If permanent completion evidence is lost after release, the
service contains that journal loss rather than inferring permission to republish.

The test adapter only maps `custody.release` to this production operation. Real
supervisor SIGKILL hooks follow completed verification/authorization/deletion:
`before-custody-release`, `custody-release-authorized`, and
`custody-released:<asset-key>`. The authorization is the restart point; no new
worker, retention timer, power-loss durability claim or OCFL repair is introduced.
Independent acceptance-owner review remains pending; PR #59 stays draft.

Review-fix verification (22 October 2026):

- `STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/TransactionCustodyTest.php --compact`: **22 passed, 2,135 assertions** before final additional status assertions. Covers two-copy release, fresh-process commit/recovery replay, preservation of every permanent byte, unfinished/partial/verification-pending custody, four actual SIGKILL cleanup windows, forged completion, damaged authoritative/journal evidence, lost completion reply, concurrent cleanup, and damaged authorization/remaining custody.
- Final restored `./bin/test.sh --compact`: **283 passed, 33 existing incomplete, 13,101 assertions**, exit 0. All frozen contracts remain unchanged from `680476b57f87b5d356275074688c35eaadea4313`.
- Three temporary production mutations each fail assertions: trusting the completion receipt during cleanup (**5 failed, 1 passed** damage cases), publishing unfinished work to make cleanup possible (**5 failed**), and requiring released copies on completed replay (**1 failed**). Sources restored in `finally` blocks before the final complete run.
- Focused lint and format check pass. Focused analysis of review-changed PHP: **0 errors**, 8 Pest fixture-property warnings and the existing comparison help message. AST-based PHPDoc coverage verifies **32 declarations** in those files. No suppressions added.
- `composer qa` again stops at unchanged `ObjectWriteLockTest.php:86` unsafe-finally lint. Separate full `composer analyse`: **1,163 errors, 189 warnings, 11 help messages**; the review adds only the 8 documented Pest fixture warnings to the previous whole-repository counts.
- Final read-only PostgreSQL check: **0 generated fixture databases and 0 generated roles**. Private test-admin configuration removed after verification; no working catalogue or Vault was used.

The projection-isolation review item is explicitly deferred to #60, including the
required healthy-plus-damaged physical OCFL/PostgreSQL isolation acceptance test.
The custody implementation and regression evidence are ready for re-review;
independent acceptance-owner certification is still required before merging.

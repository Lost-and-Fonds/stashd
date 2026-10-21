# Recoverable preservation transactions — independent Phase 1

This is a contract and test handoff, not an implemented transaction service.
Base: freshly fetched `origin/dev`, `904524164ab216be9596ce3e3346b8595bc7ac99`.
Branch: `test/recoverable-preservation-phase1`. No production PHP is changed.

## Contract

**THE VAULT IS THE TRUTH.** A transaction covers exactly one canonical Item and
zero or more independently versioned Asset Objects. PostgreSQL, search, dispatch
and a worker's exit status cannot establish archival completion.

The conceptual states below describe evidence, not required class/enum names:

| State | Required evidence and permitted recovery |
| --- | --- |
| Preparing | No sealed candidate, no logical completion. Never publish by guessing from staging. Retain any separately acknowledged custody. |
| Sealed | Persistent transaction identity bound to an immutable delta, preconditions and verified Core-owned bytes; repeat dispatch uses this same identity. |
| Assets partly published | Preserve each verified exact revision, identify it by transaction evidence, and continue only missing steps. No historical Item relationship exists yet. |
| Assets verified | Every required exact Asset revision is independently verified before Item publication. |
| Item published, verification pending | Check authoritative publication and exact pins; mere existence of a version directory is insufficient. |
| Authoritatively complete | Verified Item publication and all required Asset publications agree with the sealed transaction. A lost reply replays the existing result. |
| Projection pending/reconciled | Independent retryable consequence of authoritative completion, never permission to create another archival revision. |
| Rejected/degraded/quarantined | Invalid, conflicting or ambiguous evidence; no success acknowledgement, history rewrite, orphan promotion or evidence deletion. |

Before authoritative publication, resolve the current Item under a fenced exclusive
commit authority. Apply only explicit delta changes. Unmentioned relationships
survive. Explicit stale preconditions cannot silently succeed. For a disjoint stale
delta the tests permit either a safe merge or explicit conflict; they do not select
a product conflict policy. Each historical Item keeps its original exact pins.

Recovery must discover completed steps from authoritative evidence even when
the SQL projection is absent or disagrees. It must not infer completion from the
largest directory number, intended version, asset ownership field, queue acknowledgement,
or return value of an earlier process. Preserve valid unlinked Assets. Recover an
unambiguous sealed transaction automatically; contain uncertainty locally.

An acknowledgement here means *logical preservation completion*. Custody checkpoint
acknowledgements and network delivery of the response are separate contracts.
The `after-ack` test boundary means persisted completion before response delivery;
it does not assert exactly-once delivery to a remote caller.

## Sources and ownership

Production and existing acceptance sources inspected: `ItemArchive`, `AssetArchive`, `Vault`,
`PublicationInspection`, `ObjectWriteLock`, local storage, existing
`CrashPublicationTest`, `CommitConsistencyTest`, `ItemHistoryTest` and the archive
driver/inspector; [single-Object crash contract](crash-publication.md).
The existing Object publication acknowledgement remains the verified checksum
policy described there. Inspection currently **blocks**, rather than automatically
repairs, ambiguous physical publication material.

Architecture sources consulted in Hazel's Obsidian vault (titles identify notes;
these remain the planning sources rather than copies of their contents):

- Core vNext — Architecture Decisions 2026-10-14.
- Core vNext — Preservation Commit Candidate Contract (Draft), 2026-10-13.
- Core vNext — Contract-in — Preservation and Acquisition.
- Core vNext — Reconciliation — Preservation and Acquisition.
- Core vNext — Decisions — Recovery, Incidents and Engineering 2026-10-21.

The later recovery decisions refine the draft: authoritative completion does not
wait for SQL/search. Proposed draft field names are not ratified APIs. The
[architecture charter](https://github.com/Lost-and-Fonds/stashd/issues/38) and
[organization project](https://github.com/orgs/Lost-and-Fonds/projects/1) retain
cross-repository planning authority. No protocol/SDK/plugin changes belong here.

## Process Success Contracts

`RecoverableTransactionTest.php` specifies these observable outcomes:

| PSC | Witness |
| --- | --- |
| T01 | A real SIGKILL between existing Asset and Item calls leaves one verified Asset and no Item. |
| T02 | Eleven before/after seal, Asset, Item and acknowledgement boundaries; source removal after sealing; three fresh-process recoveries; exact bytes and immutable old history. |
| T03 | Duplicate dispatch returns the same Item revision and changes no settled Vault bytes. |
| T04 | Out-of-order disjoint candidates keep the newer relationship or explicitly conflict. |
| T05 | Two live PHP processes released from a shared gate cannot duplicate one transaction's Item/Asset versions. |
| T06 | Same ID plus different bytes cannot replace the original candidate. |
| T07 | Damaged candidate manifest or custody bytes cause containment, without deletion or Object publication. |
| T08 | Metadata delta retains existing pins and creates no Asset revision. |
| T09 | Actual disposable PostgreSQL schema removal is independently checked through PDO; Vault-only recovery and projection rebuild retain archival bytes. |
| T10 | Negative controls require independent assertions to reject real lost relationships, fabricated v99 receipts and damaged physical bytes. |
| T11 | Explicit stale exact-pin precondition cannot overwrite a newer pin. |
| T12 | Unsafe Asset key, absent source and invalid Item URI cannot publish. |
| T13 | Damaged committed checksum, historical inventory or Item payload cannot be reacknowledged or erased by repeated recovery. |
| T14 | Zero-Asset transaction creates exactly one metadata-only Item. |
| T15 | Seal identity and physical manifest digest survive producer source mutation; publication uses accepted bytes. |
| T16 | Unreachable projection service does not prevent authoritative completion or cause duplicate recovery writes. |
| T17 | Two different concurrent candidates retain every successfully acknowledged relationship or explicitly conflict. |
| T18 | Unknown transaction identity cannot adopt unrelated committed Objects as its own history. |
| T19 | A real second Item publication is detected by the independent snapshot witness. |

Every child has unreachable ordinary DB configuration. PostgreSQL projection
fixtures must use an explicitly isolated test connection; T09 refuses a schema
name outside `stashd_tx_test_<hex>`, verifies its existence before destruction and
absence afterward. An adapter boolean alone is insufficient. Never connect these
tests to a working Vault or production catalogue.

The parent performs SIGKILL only after observing the selected child's boundary
marker. Missing boundary, timeout, launch error and nonzero exit all fail. Logs
are private fixture files; child processes are reaped. Native JSON/SHA inspection
checks inventory sidecars, historical states and payload digests, exact Item pins,
Asset ownership and known fixture bytes. It does not trust `ItemArchive::read()`
as its archival witness and is not a complete independent OCFL validator.

## Execution seam

Run all tests through the repository wrapper:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php --compact
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php --filter='PSC-T01|PSC-T10|PSC-T19' --compact
./bin/test.sh tests/Acceptance/RecordsAndArchives/CrashPublicationTest.php --compact
```

`transaction-process.php` exposes `legacy.publish` solely as a baseline probe:
real `AssetArchive::preserve()` followed by `ItemArchive::commitRevision()`. It
implements no preparation, sealing, journal, recovery, SQL projection or fake
transaction receipt. All transaction operations currently exit **78 UNIMPLEMENTED**;
the new acceptance cases deliberately fail, rather than skip or pass incomplete.
Existing acceptance files are untouched.

Phase 2 supplies `STASHD_TRANSACTION_TEST_ADAPTER`, an absolute PHP file returning
`callable(array $request, Closure $boundary): array`, available in the container.
It must translate these **test-only** operations to the real application boundary:

- `prepare`: validate/adopt/seal the request; return `transaction_id`, physical
  `candidate_path`, its `candidate_sha512`, and a physical `custody_path` for damage
  injection. These are fixture inspection handles, never plugin-facing APIs.
- `execute`: prepare and commit in one process, emitting the sealing hooks too.
- `commit` / `recover`: same transaction ID, no replacement candidate supplied.
  Return `status`, `transaction_id`, and on completion exact `item` Object/version.
  Expected domain rejections return an explicit semantic status; setup, storage,
  programming and unexpected process errors must remain errors.
- `projection.describe/destroy/rebuild`: create/inspect/drop/rebuild only the
  private PostgreSQL fixture; describe supplies PDO `dsn`, `username`, `password`,
  `schema`; rebuild returns actually projected `items` as Object/version pairs.

Call the supplied boundary callback at actual completed boundaries named in T02;
never emit a marker before doing the work it describes. `item-published` occurs
after authoritative Object publication but before transaction verification.
`before/after-ack` bracket the persisted transaction completion fact. On the
selected boundary the callback waits; the supervisor kills that PHP process.
Place hooks at the application/storage seam, without implementing a parallel
transaction engine in the adapter. All JSON names above are normalized test
results, not mandated production serialization or command names.

## Unresolved decisions and bounded Phase 2

Decide these with the implementation review; do not make policy disappear inside
the test adapter:

1. Authoritative candidate/journal location, schema, immutable seal encoding,
   retention and discovery after SQL loss. Define transaction-to-OCFL publication
   linkage that proves a revision belongs to this candidate rather than matching
   coincidentally. Add an independent reader for that adopted on-disk format.
2. Fencing scope and stale-owner exclusion. Initial deployment is one local
   authoritative committer; local flock alone is not a distributed lease.
3. Disjoint stale-delta merge versus explicit conflict; metadata field merge and
   explicit removals; source observation order versus commit order. Tests select
   only non-destructive invariants and exact explicit precondition failure.
4. Automatic reconciliation of known single-Object interrupted publication versus
   quarantine. Complete version directories alone must never be promoted. Define
   what transaction evidence is sufficient, including journal damage and conflicting
   receipts. Existing Object reads must retain their conservative guarantees.
5. Byte-identical acquisition/event version policy, interrupted operation scope,
   and custody checkpoint protocol remain separate from transaction retries.
6. Supported storage backend and durability class. This suite tests process death
   on local storage only. fsync/fdatasync, directory sync, kernel/device loss,
   remote backends and power-cut testing require separate evidence.

Implement only the local Core transaction boundary and its durable evidence,
exclusive publication, exact-version reuse, delta application and recovery;
then connect a thin acceptance adapter and isolated projection fixture. Retain
all existing Object crash tests. Extend T02 to *intra-Object* crashes during a
multi-Object transaction using the existing CrashStorage boundaries, and add
deterministic stale-owner fencing beyond the concurrent-process cases here.
Add malformed/missing/conflicting transaction-journal evidence tests against the
chosen physical format; this Phase 1 does not pretend that format is settled.

Exit gate: every PSC passes against real production services, former intentional
failures reach their assertions, missing/dishonest hooks fail, and representative
production mutations (skip verification, republish a completed step, replay a full
Item snapshot, trust SQL after Vault damage) demonstrably fail relevant tests.
No checkpoint WIT redesign, replica engine, distributed storage, UI, generic workflow
framework or power-loss guarantee is included in this implementation brief.

## Recorded baseline — 2026-10-22

Environment: PHP 8.5 through the repository's `bin/test.sh` wrapper and Lerd;
fresh child processes, private temporary local OCFL storage. No working Vault
or user catalogue was used.

| Check | Observed result |
| --- | --- |
| New transaction suite | **33 failed, 5 passed, 552 assertions**; every failure identifies absent `prepare`/`execute` transaction support (exit 78 or unreachable crash hook). No incomplete cases. |
| Existing CrashPublication, ItemHistory, ItemArchive and AssetPreservation suites | **112 passed, 2,279 assertions**, rerun with production restored. |
| Complete Records & Archives suite | **33 failed, 33 incomplete, 185 passed, 3,323 assertions**. All failures are the new absent-transaction diagnostics; the 33 existing incomplete contracts remain incomplete. |
| Deliberate production mutation: discard Item pins | **3 failed, 57 assertions**; each T10 case fails its healthy precheck on zero stored pins versus two expected. Production restored immediately afterward; no mutation retained. |
| Focused formatting and lint of all three new PHP files | Passed. |
| `composer qa` | Ran; stops at the pre-existing `no-unsafe-finally` error in `ObjectWriteLockTest.php:86`. No existing files changed by QA. |
| Focused static analysis | Not green: **105 errors, 26 warnings, 1 help**, including dynamic Pest fixture binding and JSON/array/resource typing. No suppressions added. |

The five passing cases are T01, three T10 oracle controls, and T19. They prove
the real single-Asset crash observation and the witnesses' sensitivity; they
**do not prove a recoverable transaction implementation**. The remaining cases
are executable requirements whose downstream assertions are not yet exercised
against production. In particular, PostgreSQL schema destruction/rebuild and the
new transaction crash hooks have **not** been reached. Their red baseline is
missing capability evidence, not a successful recovery drill.

T10 first creates and independently verifies a healthy two-Asset Item. It then
uses a real stale full-snapshot Item call dropping relationships, supplies a
fabricated v99 receipt, or damages real stored bytes. The identical oracle must
reject each mutation. T19 creates a second actual metadata-only Item revision
and requires the snapshot assertion to detect the extra publication. These
controls catch expected assertion failures; unexpected setup/runtime errors
still fail the tests.

For the separate production mutation experiment, the encoded `pins` field in
`ItemArchive::commitRevision()` was temporarily changed from `$pins` to `[]`,
then the wrapper ran `RecoverableTransactionTest.php --filter=PSC-T10`.
All three tests failed on the independent physical-record pin count before
their intentional corruptions. An EXIT trap restored the exact original source;
`git diff -- app/RecordsAndArchives/ItemArchive.php` is empty. The restored
control cases pass in the final baseline above.

Power-loss durability, cloud storage, distributed fencing, full OCFL conformance,
and actual transaction/journal recovery remain unproven. The chosen journal format
still needs its own independent evidence reader and damage/conflict vectors in
Phase 2; the current witness does not establish an on-disk transaction-to-version
binding by merely trusting a transaction ID in a response.

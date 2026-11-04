# PR 4: interruption, authority loss and source-independent recovery

**Status: acceptance-first, incomplete.** This draft contains the first two
real-Operations interruption contracts and targets `dev` after PR #92 merged.
Do not merge this tests-only PR. No production code or runtime configuration
is changed.

The [five-PR round-trip plan](../plans/filesystem-round-trip.md) remains the
scope definition. PR 1 already proved a kill **after canonical Vault commit
and before Result acceptance** for one acquisition. PR 2 already proved
crash-safe directory admission. Existing
`FilesystemPublicationTest.php` tests a crash during staging and after
rename using the older synchronous `broadcast:publish` diagnostic.
PR 4 extends these proofs to **genuine Operations Jobs**, old and new Vault
evidence and stale authority, not a second fake execution path.

## First committed acceptance contracts

`tests/Acceptance/Integration/FilesystemRecoveryPr4Test.php` uses isolated
PostgreSQL fixtures, real Tempest CLI processes, Messenger/Durable workers,
canonical OCFL bytes and the independent Microtext verifier.

### 1. SIGKILL after destination rename but before report

- Acquire three separate Microtext Items through bounded per-file Jobs.
  Independently inspect Item/Asset OCFL v1 and exact pins.
- Freeze the actual Stash selection, delete Input A, and admit a real
  `PublishBroadcast` Job with `broadcast:build`.
- Block **the first real INSERT into `broadcast_reports`**, using a
  transaction-scoped PostgreSQL advisory lock. Its file must already have
  been renamed into B. Verify its bytes and inode.
- Require the run to be unfinished, no accepted Operations Result, no public
  publication report and independent verification of the incomplete B to
  fail. Kill the real worker with SIGKILL while the INSERT is blocked.
- Remove the test trigger, expire only the disposable Messenger delivery
  (production visibility remains 60 seconds), and recover the **same
  Assignment and frozen Broadcast run** with a fresh worker.
- Require three and only three reported files, exactly one accepted Result,
  full independent output verification, preserved original OCFL revisions,
  unchanged inode for the file already renamed, and no orphaned private
  `.stashd-publication-*` directories in B.

**Potential genuine red:** a dead process skips the publisher's `finally`
cleanup, leaving a destination-local private workspace. The original
synchronous test proves inode reuse but does *not* require the entire output
tree to pass the external no-extras verifier. The new test must reveal whether
recovery safely reclaims only its own abandoned workspace. **Do not weaken
the exact file-set oracle to hide private debris.** Do not globally delete
every matching directory without verifying provenance/ownership and that
no live attempt uses it.

### 2. Revoke the real Result writer after successful Broadcast

- Start with a separately preserved Microtext Item and a frozen selection.
  Delete Input A before the Operations Broadcast Job runs.
- Block the real `operation_results` INSERT with a PostgreSQL trigger.
  Physical output and the Broadcast's own report must already be complete,
  but no authoritative Operations Result may yet exist.
- Use `pg_terminate_backend` on the **observed blocked application session**,
  and require the original worker to observe that failure and exit. Do not
  forge a Result, an Attempt or an Assignment state.
- Restart delivery of that same Demand only after the original worker exits.
  A successor must accept exactly one Result, reuse the published inode
  and exact Vault pin, and pass external byte verification.

This is a **session-revocation** test, not yet the stronger concurrent
successor-takeover scenario. The tests deliberately keep those claims apart.

## Remaining contracts to write before implementing a general recovery change

- **Accepted custody, pre-OCFL commit:** observe a *sealed real acquisition
  transaction* after custody has been accepted. Kill the process before
  authoritative Asset/Item publication, delete source A, then replay the
  same persisted transaction. No missing bytes, no fabricated completion or
  duplicate revision. An unsealed `.preparing-` directory is **not**
  accepted custody.
- **Partial multi-Object OCFL commit:** interrupt after verified Asset
  publication but before the matching Item revision. A fresh worker must
  verify the retained plan/evidence and complete the same Item–Asset pins
  without publishing an additional Asset revision. A damaged step must
  quarantine rather than silently be overwritten.
- **Actual Operations stage-copy interruption:** stop a real Broadcast Job
  while it has some but not all bytes in its private staging file; no partial
  final filename may appear. Recover from the frozen Vault selection after
  source deletion. Distinguish this from the pre-existing synchronous test.
- **Concurrent authority takeover:** replace an active Attempt with a real
  successor while the original handler is still alive, then attempt a stale
  result write. Only the successor may satisfy the Demand. Test the actual
  Operations fence, not merely a process that was SIGKILLed.
- **Confinement and honest limitations:** retries must not overwrite
  differing destination bytes, follow symlinks or delete unrelated material.
  Record that per-file rename atomicity is *not* whole-tree atomicity,
  hostile-writer protection or power-loss durability.

Use existing transaction journals, Vault locks, custody reconciliation,
`PublicationAuthority`, Tempest facilities and Operations. No generic
recovery manager, queue, new archival history store or new public CLI
before a concrete failing acceptance requires it.

## Validation and review gates

The two initial contracts have been **committed but not executed in the
intended Lerd/PHP 8.5/PostgreSQL environment** as part of this drafting pass.
They may fail because of an actual recovery gap or a harness mistake. A
formatter/linter/fixture error is not product evidence. First run:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/FilesystemRecoveryPr4Test.php
```

Then classify each failure, prove a real negative control, and run relevant
PR 1–3/Operations/Filesystem regressions and full `lerd composer qa` after
changes. Keep expensive source-copy/crash stress out of normal CI until
runtime is measured. The full 10,000-Item application stress remains PR 5.

**Safety:** Every SQL trigger, advisory lock, database, process and filesystem
path belongs to the disposable test fixture. The worker is killed only after
an independently observed boundary, and `finally` reaps it and drops
triggers/locks. Test-only backdating of transport visibility never changes
production retry policy. No existing user's database, Vault or destination
may be reset or cleaned.

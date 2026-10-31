# OPS-C03 implementation review

Implementation review branch: `feat/operations-attempt-fencing`,
based on latest fetched `dev`, `3a8fce1118a6b3bdc5ca7ffa18526e46808ed7ac`.
No merge was performed. The workspace's submodule pointer is unchanged.

## Production API

- `AttemptAuthority::protect(ExecutionContext, callable)` protects a short database
  mutation. It locks the Job, checks actual persisted Job/Attempt identity and
  PostgreSQL wall time, executes on the shared connection, then checks again.
  Expiry rolls back the entire mutation. No department recreates fencing SQL.
- `finish()` applies that boundary to terminal transitions, including domain retry
  publication. Revoked authority is an expected false outcome, not an Ecotone
  infrastructure exception. A same-owner expired settlement retains native recovery
  intent; a superseded settlement leaves its successor and publications untouched.
- `renew()` returns acceptance; `renewal()` returns a typed `LeaseRenewal` with the
  actual database decision time and accepted deadline. It cannot resurrect an
  expired Attempt, create history, change Work or reset progress.
- `LeaseHeartbeat::run()` supervises a long external tool using Tempest processes,
  renewing between polls and stopping/reaping the tool on lost authority.
- `ExecutionArtifacts` provides the bounded checkpoint/custody/result handoff.
  Custody retains an immutable receipt and SHA-256 for real retained bytes.
  Reconciliation rehashes those bytes outside the Job lock, then fences the short
  write recording which receipts this Attempt verified. Result adoption requires
  reconciliation of all retained receipts and cannot append a second result.
- `STASHD_EXECUTION_LEASE_MS` configures execution leases; the default is 60000ms.
  Ecotone transport visibility remains a separate setting. `claimDecidedAt` is
  diagnostic database evidence, never permission for another operation.

These APIs require no Ecotone delivery knowledge from Acquisition, Preservation or
Broadcast. The full rules and the Tempest/PostgreSQL decision are documented in
[operations-attempt-authority.md](../../operations-attempt-authority.md).

## Approved contracts

All nine approved contracts PASS, with 287 assertions. The four additional
integration tests bring the focused run to **13 passed, 340 assertions, zero
incomplete cases**. See [focused.log](focused.log), [focused.xml](focused.xml) and
[acceptance-integrity.json](acceptance-integrity.json). The approved assertion file
is byte-identical to the merged version.

| Approved behavior | Result |
| --- | --- |
| Competing initial claims | PASS |
| Native redelivery cannot steal an unexpired lease | PASS |
| A living expired worker can be superseded | PASS |
| Check/write fencing at checkpoint, custody and result | PASS |
| Expiry alone revokes host and terminal authority | PASS |
| Valid database-clock renewal survives the original deadline | PASS |
| Recovery reconciles retained custody before one adoption | PASS |
| Renewal/takeover contention in both serialized orders | PASS |
| A stale real handler exception cannot affect its successor or retry budget | PASS |

The driver uses actual native Ecotone consumers, independently supervised living
processes, disposable restricted PostgreSQL databases/roles, real production host
writes, retained files and independent SQL observers. A third observer briefly
holds the contended row between the two domain decisions so the `after_first`
snapshot really precedes the second decision. No authority predicate is implemented
by that observer. A committed queue INSERT audit retains publication evidence even
when acknowledgement later deletes the queue row.

[observations.jsonl](observations.jsonl) preserves 170 sanitized action observations,
including independent Job/Attempt history, both ordered races and their snapshots,
actual database decision times, receipts and immutable native publication history.
It contains no credentials or private fixture paths. Replay after worker settlement
invokes the real production host API in a fresh process using the old context;
process death is never used as a substitute for an authority check.

Additional tests prove rollback of a mutation delayed beyond expiry while holding
the row lock, actual periodic renewal during a blocking child process, unchanged
history/publications after rejected renewal, and rejection of tampered custody.
The Operations/Ecotone focused regression run also passed 20 tests and 650
assertions; [operations-regression.log](operations-regression.log) records that
checkpoint. Complete QA below covers the final implementation.

## Adversarial proof

Every control had a passing targeted baseline, one deliberate behavioral violation,
a failure at the intended invariant, restored source and another passing baseline.
No timeout, setup failure, cleanup failure or unrelated assertion was counted.
[negative-controls.json](negative-controls.json) records exact failing assertions;
each control has baseline, violation and restored logs plus its fault patch.

| Fault injected | Expected detection |
| --- | --- |
| Claim permits an unexpired competing owner | Two executors enter instead of one |
| Renewal ignores current identity and expiry | Stale renewal is incorrectly accepted |
| Authorization is detached from a delayed unguarded mutation | Expired checkpoint is incorrectly accepted |
| Completion bypasses the fence and expiry predicate | Expired completion is incorrectly accepted |
| Handler failure bypasses the fence and current owner predicate | Stale failure is incorrectly accepted |
| Renewal ignores authority during contended takeover | Renewal/takeover outcome violates the selected order |
| Result adoption skips custody reconciliation | Premature publication is incorrectly accepted |
| Authority uses transaction-start time | A transaction delayed past expiry incorrectly commits |

All eight controls were detected, and all restored baselines passed. The repeatable
runner is `tools/verify-operations-fencing.py`; it calls the canonical test wrapper
and restores production source in `finally`. Fault patches describe deliberate
violations only; none is present in production source.

## Complete QA and cleanup

Canonical `lerd composer qa` exited **0**: **493 passed, 33 incomplete,
24,874 assertions, zero failures**, four processes, 172.13 seconds. Formatting and
lint were clean; analysis exited zero with only the same three existing help
diagnostics in Records & Archives. See [qa.log](qa.log).

Every owned worker was stopped/reaped and every owned database/role was removed.
The independent global inspection found one older Preservation-only test database
and matching role (`stashd_tx_test_827d41e078c94da1`): its tables were last modified
on **2026-10-28**, before this task. It has no Operations tables and no owned C03
resources. It was left untouched. Two older empty contract workspaces were also
preserved. Nine empty directories from this run's initial parse/setup failures
were removed using empty-directory removal only. The private test-admin copy and
inspection scripts were removed. See [cleanup.json](cleanup.json).

Other worktrees retained their recorded Git status entries, including the four
pre-existing entries in durable admission and eight in work admission. No component
source outside this dedicated worktree was edited. `git diff --check` is clean.


The only adjusted existing acceptance assertion outside C03 is the migration count
in `ConfigurationTest`: the new eighth migration changes the expected count from
seven to eight. No Preservation contract or approved C03 assertion was weakened.
No dependency, vendor patch, Tempest fork or upstream repository was changed.

## Changed files

Production:

- `app/Operations/JobRuntime.php`
- `app/Operations/AttemptAuthority.php`
- `app/Operations/AuthorityLost.php`
- `app/Operations/DTO/LeaseRenewal.php`
- `app/Operations/LeaseConfig.php`
- `app/Operations/lease.config.php`
- `app/Operations/LeaseHeartbeat.php`
- `app/Operations/ExecutionArtifacts.php`
- `app/Integration/Migrations/ExecutionArtifactsTable.php`

Test integration and verification:

- `phpunit.xml`
- `tests/Acceptance/Integration/ConfigurationTest.php`
- `tests/Feature/AttemptAuthorityTest.php`
- `tests/Support/Operations/LocalOperationsDriver.php`
- `tests/Support/OperationsFencing/FencingExecutor.php`
- `tests/Support/OperationsFencing/LocalFencingDriver.php`
- `tests/Support/OperationsFencing/worker.php`
- `tools/verify-operations-fencing.py`
- `docs/development/operations-attempt-authority.md`
- This verification directory: report, integrity record, logs, fault patches,
  JUnit, sanitized observations and cleanup evidence.

## Limits and owner attention

The bounded custody handoff is not full OCFL/Vault integration. Its caller must
retain immutable files accessible to a recovering worker. Preservation still owns
archival validation and the durable storage integration. SQL authority cannot undo
remote effects; external tools and services need stable idempotency identities and
reconciliation. A process killed by the operating system may leave external work
in progress even though its Attempt later loses authority.

Operators should choose a lease duration with margin for expected database latency
and worker pauses. Executors must renew between bounded operations or use the
heartbeat helper for blocking tools. They must never hold the protected transaction
across network I/O or use a context/renewal receipt as continuing permission.

No additional protocol or architecture decision is required for this slice.
OPS-C04 lifecycle expansion, C05 cancellation/pause, C08 resource scheduling and UI
remain outside scope. The tests cover representative real interleavings, not an
exhaustive model of every partition, hardware failure or host-clock discontinuity.

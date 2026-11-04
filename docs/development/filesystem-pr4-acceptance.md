# PR 4: interruption, recovery and stale-worker authority

**Acceptance-first; stop for architectural review. Do not merge.**
PR [#93](https://github.com/Lost-and-Fonds/stashd/pull/93) changes tests and this
report only. No production fix, general recovery service or PR 5 work is included.

## Baseline and failure classification

The latest baseline CI run,
[41745192142](https://github.com/Lost-and-Fonds/stashd/actions/runs/41745192142),
failed its two new tests before recovery assertions: an Assignment **URN** was
bound to a PostgreSQL **UUID** column. This was a harness defect, not a product
failure. Reads now use `Identifier::parse(...)->uuidFor(Assignment::class)`.
The baseline formatter gate passed.

Local execution uses Lerd's PHP **8.5.12**, PostgreSQL **16.15**, the pinned
Tempest injected-connection branch and real Messenger/Durable workers. Initial
local blockers were environmental: the private, ignored projection test-admin
file was absent, and Lerd debug capture contaminated CLI JSON. The private
fixture config was provisioned with mode 0600 from the existing local service;
debug capture was temporarily disabled. Application configuration and database
authentication were not changed. Each scenario creates and disposes its own
database, unprivileged role, Vault and destination.

Two additional harness assumptions were corrected while writing confinement:
failed worker drains can print diagnostics before their JSON receipt, and
Durable may execute all its configured retries in one drain. The test now checks
the worker exit, actual persisted Attempts and their outcomes, not a guessed
one-successor count. Reading conflicting bytes can update **atime**; confinement
checks inode/device/mode/size/mtime/ctime and exact bytes instead of forbidding
that harmless read effect. No preservation or safety assertion was relaxed.
The SIGKILL witness also retains the **first** observed process exit status:
PHP clears its termination-signal field on later `proc_get_status()` calls.
An intermediate run exposed that harness mistake; it was fixed rather than
removing the requirement to observe signal 9.

## Acceptance matrix

All cases live in
`tests/Acceptance/Integration/FilesystemRecoveryPr4Test.php`.
`Pr4Recovery` only supervises processes, observes database/evidence boundaries
and accelerates fixture delivery eligibility. It never performs preservation,
creates an authoritative Result or replaces an Attempt token.

| Contract | Independently observed boundary and required outcome | Finding |
| --- | --- | --- |
| Accepted custody before any OCFL Object | Hold the actual Asset Object lock. A real `AcquireFile` worker seals custody and its plan, but publishes no Object. SIGKILL it, delete A, redeliver the same Assignment. Recover the same transaction, exact bytes and one v1 Item/Asset pin, then Broadcast from Vault. | Pass |
| Asset published before Item | Hold the actual Item Object lock. Verify the Asset's native OCFL inventory, historical sidecars and payload before killing. With A deleted, complete the Item pin without changing the Asset inventory, payload inode, candidate or plan. | Pass |
| Damaged partial Asset | Corrupt only the fixture's already published Asset after the crash. Redelivery must quarantine the transaction, retain custody and immutable evidence, create no Item and accept no Result; it must not overwrite the damaged bytes. | Pass: negative control |
| Kill after rename, before report | A PostgreSQL trigger blocks the first actual `broadcast_reports` INSERT after the first of three files is renamed. Kill, then recover the same frozen run/Assignment with A absent. Require exact output, unchanged first inode/history, three reports and one Result. | **Product failure: orphan workspace** |
| Interrupted Operations staging | Observe `0 < staged bytes < source size` in a real worker, stop it and SIGKILL. No final filename or accepted Result may exist. Recover from the frozen selection with A absent; verify full size/SHA-256 and unchanged canonical history. No dead staging workspace may remain. | **Product failure: orphan workspace** |
| Revoke Result writer | Block the actual Result INSERT after complete Broadcast, terminate that observed PostgreSQL publication session, wait for worker exit, then redeliver. Require no old Result and exactly one successor Result, same inode and archival pin. | Pass |
| Concurrent stale takeover | Block the living handler's Broadcast report, revoke its **separate claim session**, redeliver to a concurrent worker, and observe a different persisted `current_attempt` while the old process is still alive. Release the old handler; only the successor's Attempt may own the accepted Result. | Pass |
| Conflicting final bytes | After interrupted rename, change only B's final bytes. Real successor Attempts must fail without replacing those bytes or accepting a Result. | Pass |
| Final-file symlink | After interrupted rename, replace the destination with a link to an outside sentinel. Recovery must reject it and retain the exact link and outside bytes/inode. | Pass |
| Ancestor symlink | Replace the final file's parent with a link to an outside directory. Recovery must not traverse it or create outside files. | Pass |

Each confinement variant also keeps an unrelated ordinary file and an
operator-owned `.stashd-publication-<32 hex characters>/bytes` lookalike.
Neither may be removed or changed. These tests intentionally do not run a
no-extras verifier on a destination containing operator-owned extras: they
verify the obstruction and unrelated material independently. The clean-output
round-trip test retains the stricter external no-extras oracle.

The large-copy case is about 112 MiB, not the PR 5 10,000-Item stress corpus.
It fails explicitly if it cannot observe a genuine incomplete staging file;
a timeout or an already finished copy is not a crash-recovery success.
The two OCFL boundaries also observe Linux `/proc/<worker>/wchan` waiting on
the fixture-held Object flock before stopping the worker. Waiting only for an
inventory sidecar could otherwise race the remaining Asset verification.

## Confirmed failing boundary

`FilesystemPublisher::publish()` creates a random destination-local
`.stashd-publication-*` workspace containing a plan, name markers and, while
copying, a `bytes` file. Its `finally` calls `cleanup()` on **that invocation's**
workspace. SIGKILL cannot execute either that cleanup or `copy()`'s `finally`.

The successor creates a different random workspace. It safely reuses matching
published files, completes the frozen reports and records its Operations Result,
then removes only its new workspace. The old workspace remains:

- after rename it retains the plan/name markers;
- during staging it also retains the incomplete private byte copy.

Thus these are not lost archival bytes or partial **final** filenames. Recovery
does restore the promised file contents. The failure is that completed recovery
leaves application debris in B, so the independent exact-tree oracle rejects B.
The original failing acceptance remains red; neither the verifier nor the
workspace assertion is removed or skipped.

The dead workspace currently has no durable binding to a frozen run or owning
Attempt, nor a checked live-owner claim. A matching basename, age or PID alone
cannot safely authorize deletion. The lookalike confinement controls expressly
forbid using a glob as a cleanup policy.

## Proposed minimal production change — not implemented

Review a **FilesystemPublisher-local workspace lifecycle**, not a generic
recovery manager:

1. Bind a private workspace to its existing frozen Broadcast run and execution
   ownership using minimal local evidence. Keep Operations' existing Attempt and
   session authority; do not invent a second job history or queue.
2. Hold a non-expiring local ownership lock while using that workspace. A retry
   may inspect/reclaim only a proven same-run workspace whose previous owner is
   no longer active, with all paths and entry types confined and rechecked.
   If ownership is absent, contradictory or live, retain the workspace and fail
   honestly rather than treating an ambiguous directory as disposable.
3. Leave verified final files alone. Continue existing frozen-selection replay
   and exact-byte/inode reuse; never prune unrelated files or follow symlinks.

The exact evidence/locking design is the architectural decision requested here.
`BroadcastCoordinator` already uses `BroadcastStore::locked()` to serialize one
Broadcast, and `BroadcastInvocation` already retains its run and Broadcast
attempt IDs. Prefer reusing those facts over generating new identities or
threading all of Operations through a publisher. The local lock would establish
workspace liveness even if a database session disappears while its process is
still copying; it would not replace the existing SQL authority fence. An
`AssignmentContext` contains identifiers, not publication authority. Do not
introduce a generic recovery API merely to satisfy a Filesystem cleanup contract.

No production change is currently justified for sealed-custody recovery,
Asset-before-Item completion or stale Result authority: the existing facilities
already satisfy those tested boundaries. Do not duplicate
`PreservationTransaction`, its candidate/plan/history, Vault Object locks,
`PublicationAuthority`, `SessionActivityClaims` or upstream redelivery.
Filesystem acquisition already accepts custody through the sealed preservation
transaction. `CustodyHandoff`'s Operations receipts were also reviewed; inventing
parallel receipt rows in this test would neither prove the actual handler path
nor improve its existing recovery evidence.

## Existing coverage reviewed and framework choices

- **PR 1**: source-independent single-file round trip; SIGKILL after complete
  Vault publication but before Operations Result acceptance. PR 4's custody and
  partial-Object boundaries occur **earlier**.
- **PR 2**: directory paging/bounded per-file admission, admission rejection,
  admission/cursor SIGKILL, unchanged rescans and replay. Not rewritten here.
- **PR 3**: stable identity and exact old/current revision selection across
  mutation, missing-source retention and historical Broadcast. Not duplicated.
- **FilesystemPublication**: synchronous diagnostic staging/rename interruption,
  conflict/symlink refusal and corruption checks. PR 4 uses actual admitted
  Operations Jobs and tests obstruction **after a crash**, not only on first use.
- **Operations**: reviewed production Department concurrency/delivery/fencing
  contracts, especially live-owner exclusion, revoked mutation rollback,
  superseded living handlers and custody reconciliation. PR 4 adds a real
  Filesystem handler with canonical OCFL evidence, not another probe handler.

Installed Tempest documentation **and implementation** were inspected:
`docs/1-essentials/03-database.md`, `docs/2-features/16-process.md`,
`docs/2-features/05-file-storage.md`, `Query`, `SelectQueryBuilder`,
`GenericProcessExecutor`, `InvokedSystemProcess` and `GenericStorage`.

- Ordinary observation queries use Tempest's builder. The existing fixture's
  independent PDO session executes the built SQL, including PostgreSQL quote
  translation matching `Query::compile()`, without rebinding the parent test
  container to a worker connection. This is a read-only test observer, not new
  application persistence. Revisit if Tempest supplies detached compilation.
- PostgreSQL trigger/advisory-lock barriers, `pg_locks`, backend termination and
  transport-time backdating remain small explicit SQL operations. The generic
  builder has no equivalent for those database-specific fault controls.
- Tempest's process facility supports real signals. These tests nevertheless
  **reuse** `start_configuration_cli`/`finish_configuration_cli`, which already
  supply the isolated application's credentials, environment and actual PHP
  child handles across PRs 1–3. Replacing that shared harness would be an
  unrelated process abstraction refactor. New waits are bounded, missing
  boundaries fail, and `finally` kills/reaps owned children. SIGKILL is checked
  as the actual termination signal.
- Native `lstat`, inode checks, stream sizes and hashes are independent witnesses
  that deliberately do not trust the implementation's storage/read receipts.
  Production work still uses the normal Tempest/Operations/Vault facilities.

## Validation

Run serially in the configured Lerd fixture:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/FilesystemRecoveryPr4Test.php
```

Final serial run: **8 passed, 2 genuine product failures, 2,175 assertions,
exit 1**, 362.64 seconds while other verification also ran. The failures are
only the rename no-extras oracle and the stage-copy orphan-workspace assertion.
Before those assertions, all expected recovered bytes, report/Result counts,
canonical histories and existing-file inode checks pass. The negative control,
both OCFL recovery boundaries, live takeover and all confinement variants pass.

| Verification | Observed result |
| --- | --- |
| PR 1–3, synchronous Filesystem publication and production Department concurrency/delivery/fencing regressions | **37 passed, 4,890 assertions**, exit 0; 662.79 s while QA also ran. |
| Acquisition, Broadcast, Catalogue fencing and work-context regressions | **15 passed, 663 assertions**, exit 0; 173.27 s. |
| Independent Microtext and mutation Python checks | **11 passed**, including corrupt bytes, unexpected directories, symlinks and latest-head substitution rejection. |
| Final `lerd composer qa` | Formatting/lint pass; analysis has only four pre-existing informational notices. **594 passed, the same 2 product failures, 42 pre-existing incomplete contracts, 31,301 assertions**, exit 1; 407.07 s, eight processes. |

The first regression command uses `STASHD_TEST_PROCESSES=1 ./bin/test.sh` with:

```text
tests/Acceptance/Integration/FilesystemRoundTripPr1Test.php
tests/Acceptance/Integration/FilesystemDirectoryPr2Test.php
tests/Acceptance/Integration/FilesystemMutationPr3Test.php
tests/Acceptance/Integration/FilesystemPublicationTest.php
tests/Acceptance/Operations/DepartmentConcurrencyContractTest.php
tests/Acceptance/Operations/DepartmentDeliveryContractTest.php
tests/Acceptance/Operations/DepartmentFencingContractTest.php
```

The second uses `lerd composer test --` with:

```text
tests/Acceptance/Integration/AcquisitionTest.php
tests/Acceptance/Integration/BroadcastTest.php
tests/Acceptance/Integration/CatalogueFencingTest.php
tests/Acceptance/Integration/WorkExecutionContextTest.php
```

These durations are execution evidence, not performance benchmarks. Red product
cases remain part of normal CI; there are no expected-failure markers or
assertion bypasses. The opt-in 100/101/1,000-Item stress and PR 5's 10,000-Item
application run were not repeated for this tests-only change.

The private test-admin file created for this run was removed, generated Python
bytecode was removed, and Lerd debug capture was restored to its original
enabled state. No test PHP worker remains. Final PostgreSQL inspection shows
only the same two pre-existing fixture-named databases/roles seen before this
work, with no active sessions; their ownership is unknown, so they were left
untouched. There are no additional fixture databases or roles from these runs.

## Limits

These tests demonstrate **process-crash** recovery on the local filesystem and
PostgreSQL stack. They do not demonstrate fsync/device or **power-loss**
durability, whole-directory atomicity, distributed filesystem fencing, or
protection against **hostile concurrent writers**. A final checked rename is
not an atomic no-replace operation. Operators must continue excluding external
writers; the symlink tests cover observed unsafe paths, not an adversarial
check/rename race. Stale-worker **Result** rejection does not imply fencing of
every filesystem side effect.
The OCFL interruption harness requires Linux wait-channel visibility; an
unobservable kernel wait is a harness/environment failure, not a product verdict.

All intentional corruption, links, session revocations and deletes are confined
to disposable fixtures. No working Vault, existing database, source or output
is reset, recovered or cleaned by these tests.

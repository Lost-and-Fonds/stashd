# OPS-C02 durable delivery: implementation and proof limits

The approved seven contracts from draft [PR #77](https://github.com/Lost-and-Fonds/stashd/pull/77)
are imported unchanged from `22d23df`. That commit changes only formatting;
its [complete CI run](https://github.com/Lost-and-Fonds/stashd/actions/runs/41000126637)
passed. The implementation branch starts at current `dev`, `42327fb`, containing
merged PR #74. No merge or OPS-C03 work is included.

## Implementation decision

The existing production runtime satisfies these seven bounded contracts. There
is no production change, migration, dependency, alternate queue, or delivery
ledger. Job admission still writes the Job and native Messenger outbox using
the existing single DBAL transaction. Messenger's `OutboxSender`, Doctrine
transports, retry listener, `Worker`, and the existing completed-Job guard do
the delivery and execution work.

`LocalDurableDeliveryDriver` is test support. `phpunit.xml` installs it for the
test suite. The specification README remains an unchanged description of the
original tests-only proposal; its statement that no driver is installed refers
to that proposal, not this implementation checkout.

The driver reuses `LocalOperationsDriver` for disposable database provisioning,
Tempest migrations, real Work admission, Job observations and the independent
probe executor. Its only change is accepting the additional exact private
`operations-delivery` workspace suffix. All six imported files, including
the assertions and independent fixture, remain byte-identical to `22d23df`.

Each fixture has its own randomly named PostgreSQL database, schema and
unprivileged role. Provisioning requires the existing private, ignored local
test-admin file; no administrator credentials reach relay/worker processes.
Database identity checks reject application credentials. Probe effects use
a separate connection and an append-only sequence with no Job uniqueness
constraint. Outbox observers decode native envelopes and reject orphan Job
references rather than making them disappear through an inner join.

## Failure controls and framework choices

Tempest's installed process documentation and `GenericProcessExecutor`,
`PendingProcess`, `InvokedSystemProcess` and `ProcessResult` were inspected.
All application children run through Tempest processes. A bounded, detached
test supervisor owns the live handle across the specification's fresh driver
objects; request, PID, kill command, exit and reaping evidence live in the
private workspace. It signals SIGKILL and then waits, rather than calling
`stop()` before `wait()`, which lost the exit code in the first experiment.
The crash proof requires exit 137 and an actually running child at the signal.
Supervisors enforce their own deadline and reap children on error.

The installed Tempest database facilities remain responsible for application
migrations. They do not expose PostgreSQL deferred constraint triggers,
`pg_stat_activity`, native Messenger visibility timestamps, or deliberately
rejected physical transport inserts. Small test-only PDO operations exercise
those database-specific controls on the validated disposable connection. This
extends the existing fixture pattern, not production persistence. Revisit only
if the installed framework supplies these exact fault/observation facilities.

The rejection trigger actually raises `P0001` on the selected queue's INSERT.
A PostgreSQL sequence records that the real write reached it, even though the
statement rolls back. Admission requires a failed child and the expected
database error. Destination outage requires a rejected destination write,
absence of a destination envelope, and a retained native retry envelope.

For admission, an initially deferred constraint trigger pauses the actual
COMMIT after both inserts. For relay and worker crashes, a BEFORE DELETE trigger
pauses the native transport acknowledgment. The parent independently requires
an active PostgreSQL transaction in `PgSleep`, with a COMMIT or DELETE query as
appropriate. An acknowledgment barrier also requires the original native
delivery to have `delivered_at` set. The approved tests inspect destination,
Job and effect state while that child is still paused, then request SIGKILL.

After SIGKILL, PostgreSQL may still be sleeping in the disconnected client's
statement. The driver cancels only that recorded backend's statement and waits
for the session to disappear before dropping the barrier. This is test barrier
cleanup after proven process death, not a claim about automatic production
backend detection time. Teardown removes the disposable database even if
supervisor verification fails.

Visibility expiry updates the retained native envelope's delivery timestamp;
stalled-forward recovery advances its native retry availability. Neither
operation invents a message or Job. Subsequent fresh production workers consume
those actual envelopes. Reverse forwarding makes each requested native outbox
row eligible in turn, runs the real relay, and reads destination records in
physical insertion order. It does not sort an answer to match the request.

## What each passing proof establishes

| Contract | Actual evidence | Limit |
| --- | --- | --- |
| Outbox insert rejection | Real rejected INSERT; independent connection sees no Job or envelope; legitimate readmission works | This specific database error in the shared local transaction |
| Admission death before commit | Active COMMIT barrier, SIGKILL/reaping, backend rollback, no retained Job/envelope, readmission succeeds | Controlled deferred-trigger window; backend cancellation removes the artificial sleep |
| Restart before relay | Committed queued Job and native outbox survive fresh runtime processes; one independent effect appears | Local process/connection restart, not machine/power/storage failure |
| Forwarding outage | Actual target INSERT rejection, retained native retry envelope, restored write path and same Job completes | PostgreSQL destination write outage; not a remote broker/network partition, nor exhausted failure-queue recovery |
| Relay death before ack | Actual destination record plus unacknowledged outbox, SIGKILL, native visibility expiry and replay; one Attempt/effect | The first destination invitation is still queued while relay replay occurs; no live expired-worker fencing |
| Worker death before ack | Persisted completed Job and independent effect, unacknowledged native message, SIGKILL and replay without another Attempt/effect | Death is after both completion and effect, not between external effect and Job completion |
| Reverse forwarding | Native destination insertion order is reversed; both distinct Jobs complete with their own effect | Two independent Jobs; not causal ordering of dependent operations or sustained fairness |

The probe's single persisted effect is not exactly-once arbitrary external
effects. Lease renewal, live expired-worker fencing, comprehensive recovery,
remote-broker failure modes, cancellation and OPS-C03 remain unproven.

## Adversarial verification

The repository-owned [focused verifier](../../tools/verification/ops_c02_negative.py)
replaces the permissive local `/tmp` runner. Its
[usage and safety notes](../../tools/verification/README.md) describe prerequisites,
private fixture configuration, isolation, interruption and artifact handling.
It resolves Core from its own file and always uses `bin/test.sh`.

```sh
python3 -B -m unittest discover -s tools/verification -p test_ops_c02_negative.py -v
python3 -B tools/verification/ops_c02_negative.py
```

A passing seven-contract baseline is mandatory. A live regression then changes
only the test fixture's administrator lookup to a guaranteed absent file. The
resulting real setup failure must be rejected as **verification error**. The
actual private administrator file is never moved or read by the runner.

Each control requires the exact selected assertion/exception location and its
specific observed violation. JUnit establishes failure identity; PHPUnit hook events independently reject
setup/teardown errors, including a second error after an expected assertion. Console
diffs expose persisted Job/effect IDs and physical destination order. A missing
barrier requires a successful un-killed child exit before its barrier. Lost
outbox intent requires independently queried state at settlement timeout: one
queued Job, zero retained native envelopes and zero effects. Arbitrary timeouts,
setup/teardown errors, extra failures and infrastructure exceptions cannot count
as detected sabotage. Results distinguish **detected**, **not detected** and
**verification error**; every unexpected error fails the overall run.

| Mutation | Selected contract and detected failure |
| --- | --- |
| Remove admission's transaction around Job/outbox writes | Rejection contract observes a surviving Job |
| Remove the commit pause | Admission crash contract rejects a child that exited without reaching its barrier |
| Skip actual destination send while acknowledging | Relay crash contract observes no forwarded invitation |
| Delete durable outbox on restart | Restart contract cannot settle its queued Job |
| Allow completed Job claims | Worker redelivery contract observes repeated effect/Attempt behaviour |
| Execute the independent probe INSERT twice | Restart contract observes both effect rows |
| Let the rejection trigger return successfully | Rejection contract rejects the successful child exit |
| Reverse the requested forwarding iteration | Ordering contract observes the wrong physical destination order |

The runner restores original bytes after each mutation, verifies source hashes,
and compares every worktree's status, diff and existing dirty/untracked bytes.
It refuses linked/shared mutation targets and concurrent verifier runs. SIGINT
and SIGTERM finish the current bounded test cleanup before source restoration
and exit. SIGKILL, machine failure and concurrent outside edits remain explicit
limits: private original-byte backups are retained for manual recovery rather
than overwriting unexpected edits. A restored seven-contract run must pass.

The attachable allowlisted JSON manifest is generated at
`tmp/ops-c02-verification/manifest.json`. A successful reviewed result is retained
at [verification/ops-c02/negative-controls.json](verification/ops-c02/negative-controls.json).
It includes all eight expected invariants, actual results, assertion locations,
observed IDs/counts/order, source hashes and worktree status hashes. Raw console
output, JUnit exception reports, absolute paths and credentials are excluded.
Do not attach the whole ignored `tmp/` directory. Native driver observations
remain local in ignored `tmp/operations-delivery-evidence.jsonl`.

## Specification-owner review

No assertion contradiction was found. These contracts are satisfied by the
merged production foundation, so the implementation is a real test driver and
verification rather than speculative production changes. The outage proof is
specifically the local Doctrine variant allowed by the accepted README. Its
retry action recovers the retained outbox retry, not an exhausted failure queue.
The synthetic sleep cleanup, explicit visibility advancement and narrow crash
windows above are material limits of the proof, not additional production
recovery guarantees.

This implementation is published separately from the tests-only PR #77 for
final review. Existing worktrees and their uncommitted work were preserved;
the workspace submodule pointer was not updated. No merge is included.

## Final validation

Reverification uses the repository's Mago commands and canonical test wrapper:

```sh
python3 -B -m unittest discover -s tools/verification -p test_ops_c02_negative.py -v
python3 -B tools/verification/ops_c02_negative.py
lerd composer github:fmt
lerd composer github:lint
lerd composer github:analyse
STASHD_TEST_PROCESSES=8 ./bin/test.sh --compact --colors=never
```

The focused verifier runs the seven contracts both before sabotage and after
restoration. The Python regression checks cover the missing-fixture false
positive, a specific surviving Job, combined cleanup/unrelated failures,
wrong assertions, infrastructure exit codes, a passing mutant, deferred signal
handling and restoration of pre-existing uncommitted bytes.

Final publication-tooling verification passed:

| Check | Observed result |
| --- | --- |
| Python classifier/restoration regressions | 7 passed |
| Unmodified baseline | 7 contracts passed, 177 assertions |
| Live missing-fixture counterexample | Rejected as the expected verification error |
| Eight negative controls | All 8 detected at their specific violations; no unexpected verification errors |
| Restored contracts | 7 passed, 177 assertions |
| Source integrity and all worktree snapshots | Preserved; before/after hashes in the retained manifest |
| Mago format check and lint | Passed |
| Mago analysis | Passed with the 3 existing Records & Archives help messages |
| Full suite, 8 processes | Exit 0: 449 passed, 33 incomplete, 10 deprecated, 23,782 assertions, 66.28s |

The complete suite's assertion count can vary with its existing polling and
concurrency checks. Passing test counts and the seven focused contracts are
unchanged. After final QA, PostgreSQL and process audits found zero new fixture
databases/roles, zero delivery backend sessions, zero delivery runtime/supervisor
processes and zero private fixture roots. The pre-existing disposable database
and role were preserved. The temporary private administrator file and temporary
report files were removed; no original-byte recovery backups remain.
All six imported specification files remain byte-identical to `22d23df`;
production code has no diff. Existing work in every other worktree was preserved.
Publication is through a separate draft implementation PR for final review;
PR #77 and OPS-C03 are outside this change.

Mago's analyzer excludes tests and does not analyze Python; the driver is checked
by Mago formatting/lint, real execution and the eight controls. The Python
verifier uses only the standard library and is exercised by its regression checks
and live PostgreSQL/Messenger run. OPS-C03 and remote-broker proof limits remain
those described above.

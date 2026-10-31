# OPS-C03 — execution Attempts, leases and fencing

These are **independently authored, outside-in Pest acceptance contracts**, based
on the agreed Stashd Core vNext OPS-C03 and OPS-PSC04 requirements. They follow
the merged Operations admission and durable-delivery slices (PRs #74, #78 and #80).

This branch is a **tests-only specification**. The new tests intentionally
report INCOMPLETE until a real driver exists, and they must not be rewritten
to make an implementation green.

## Safety contract

An Ecotone delivery is an invitation to execute, **not** ownership of a Job.
Every authoritative host mutation must be conditional on a current durable
execution claim. A running process can become stale when its claim expires;
its continued existence, a cached `ExecutionContext`, its delivery generation,
or an old timestamp cannot authorize new work.

A Job can have multiple historical Attempts but **at most one current
authoritative Attempt**. Distinct Attempts may overlap as live processes.
The latest Attempt must have a distinct identity even when redelivery has
the **same delivery generation**. That generation is a routing revision,
not a fencing token.

Lease expiry itself removes authority, even before another worker takes over.
A valid renewal extends the current claim only. A stale worker cannot renew,
write checkpoints, accept custody, or publish a result.

Preserved custody and checkpoints remain durable when an Attempt dies. A
successor must inspect and reconcile that evidence before adopting or committing
an authoritative result; losing a worker must not erase already acknowledged
custody or create a second authoritative publication.

**Fencing does not undo external side effects.** This group does not assert
exactly-once provider/API calls or Broadcast delivery. External effects need
stable idempotency identities and independent reconciliation. The bounded
test host must protect *authoritative Core-side writes* using the actual
production fence; it must not pretend to fence a third-party service.

## Nine required scenarios

| Scenario | Failure / overlap | Protected observation |
| --- | --- | --- |
| Competing initial claims | Two live workers meet at the real claim boundary | Only one persisted Attempt enters execution |
| Redelivery with valid owner | Native transport visibility expires while Job lease is still valid | Another delivery cannot take ownership |
| Live expired worker | A remains alive past its DB lease; B recovers in the same message generation | B has a new Attempt; A loses authority and cannot renew |
| Host-boundary fencing | A begins a write while valid; expires and B claims before the mutation | Every A write is rejected; each corresponding B write succeeds |
| Expired without successor | A attempts every host write, renewal and completion after expiry, before B claims | Expiry alone revokes write and terminal authority |
| Long-running renewal | A renews before expiry; time passes beyond its original deadline | No new Attempt; B cannot steal the renewed claim; A can still checkpoint |
| Custody before recovery | A persists verified custody then crashes; B recovers | B must reconcile retained custody before publication; replay cannot publish twice |
| Concurrent renewal/takeover | Competing database operations, exercised in both serialized orders | Renewal excludes takeover; takeover rejects stale renewal; one authoritative Attempt |
| Stale handler failure | Living A throws from its real handler after B takes over | B's state/claim/history, domain budget, generation and publication history remain intact |

These tests are stronger than a SQL condition on `jobs.status`. They must
exercise real independent processes, actual concurrent claims, persisted
Attempt history, database-observed lease expiry, and an actual guarded host
write boundary.

## Driver installation

Add an autoloadable implementation of
`Tests\Support\OperationsFencing\FencingDriver`, then set
`STASHD_OPERATIONS_FENCING_DRIVER` to its class name in the test environment.
Do **not** install a default driver in this test-specification branch.

The fixture passes a unique `workspace` path to each action and constructs
a **fresh driver object every time**. Only the underlying disposable
PostgreSQL/Ecotone installation and separately managed worker processes
may retain state between actions.

A missing driver must be INCOMPLETE. A configured but invalid driver, unsafe
fixture, unexpected setup failure, non-reaped child or timeout must FAIL.
Do not replace missing production fence support with a test-only predicate
that simply compares supplied strings.

### Test-only action dictionary

These are observable requests, not a required production class/API shape.

| Action | Arguments beyond workspace | Required real-world effect or observation |
| --- | --- | --- |
| `job.prepare` | `name` | Create one durable, separately scoped probe Work and its real queued Job; return `job_id` |
| `job.read` | `job_id` | Independently inspect stable authoritative Job fields: `id, state, claim_attempt_id, lease_expires_at, domain_failures, delivery_generation` and any persisted error; exclude observation timestamps |
| `worker.compete_for_claim` | `job_id, workers` | Overlap two actual worker processes at a database claim boundary, prove both attempted it, and return `observed_competing_processes`, `entered_executor_attempt_ids` |
| `worker.start_paused` | `job_id, worker` | Actual worker claims Job and enters real executor, then blocks before completing; return `job_id, attempt_id, delivery_generation, lease_expires_at` |
| `worker.try_redelivery` | `job_id, worker` | Deliver an eligible *native* message through a separate worker; return `received_native_message, acquired_attempt, original_worker_alive, database_lease_valid`; observe validity at B's claim decision |
| `worker.recover` | `job_id, worker` | Let a fresh worker recover the expired Job through real claim path; return `job_id, attempt_id, delivery_generation`; `pause_before_reconciliation: true` holds the executor before its normal reconciliation, without bypassing the real claim |
| `worker.try_complete` | `attempt_id` | Let the selected real executor attempt its normal terminal transition; report `accepted` based on persisted status, not merely an exception type |
| `worker.fail` | `attempt_id, message` | Resume the selected living executor so it throws this error through its normal handler failure path and native settlement; return `original_worker_alive_at_failure, handler_error_observed, failure_transition_reached, job_failure_accepted, native_delivery_settled` from actual observations |
| `worker.crash` | `attempt_id` | Kill and reap the actual worker process; return `terminated_process` only after verified termination |
| `worker.release_all` | `job_id` | Release/stop test-controlled workers safely; fail on leaked children |
| `attempts.read` | `job_id` | Return persisted `attempts` list, `current_attempt_id` (or null), each historical Attempt's `id, started_at, outcome` and other persisted history fields, plus `authoritative_attempt_ids, database_lease_valid` from the same PostgreSQL snapshot; an outcome may remain null while execution has not ended |
| `transport.publications` | `job_id` | Independently read immutable committed native publication history (including retry inserts), canonically ordered, even for messages later acknowledged/deleted; do not infer publications from current queue length |
| `transport.expire_delivery` | `job_id` | Advance a real delivered Ecotone message to redelivery eligibility, **without** touching the Job lease |
| `lease.await_expiration` | `attempt_id` | Observe PostgreSQL clock past actual lease deadline; return `database_lease_expired, original_worker_alive` |
| `lease.renew` | `attempt_id` | Attempt renewal through the actual authoritative lease path; return `accepted, new_deadline_later, used_database_clock` if accepted; these observations require SQL/clock evidence, not host-computed deadlines |
| `lease.compete_with_takeover` | `job_id, attempt_id, successor_worker, first_mutation` | Race real renewal with native-consumer takeover; return `observed_competing_processes, overlapping_database_operations_observed, received_native_message, original_worker_alive, renewal_accepted, takeover_accepted, successor_attempt_id, renewal_decision_at, takeover_decision_at, authority_snapshots`; see ordering rules below |
| `lease.await_original_deadline` | `attempt_id, original_deadline` | Wait for DB time to pass original deadline while renewed lease remains valid; return `original_deadline_passed, renewed_lease_still_valid` |
| `host.race_write` | `job_id, attempt_id, kind, value, successor_worker` | Hold A at the real write boundary while valid, then observe expiry and B's real claim, resume A; return `write_boundary_reached_while_valid, original_worker_alive, successor_claimed_before_write, successor_attempt_id, accepted` |
| `host.try_write` | `job_id, attempt_id, kind, value` | Invoke the actual Core-gated host path for a `checkpoint`, `custody`, or `result`; return `accepted` and rejection `reason` |
| `host.try_publish` | `job_id, attempt_id, coverage` | Attempt authoritative result adoption/commit through real host protection; after recovery require reconciliation; on acceptance let normal execution finish and observe durable completion |
| `host.reconcile_custody` | `job_id, attempt_id` | Validate durable acknowledged custody, return verified `custody_keys` in canonical order as `verified_custody_keys` |
| `host.records` | `job_id` | Independently read persisted `checkpoints`, immutable `custody` receipts (stable identity, digest and original acknowledgement), `results`; no unique constraint or projection hiding duplicate writes |
| `fixture.dispose` | none | Kill/reap all children, remove private credentials, database, role, transport data and host fixture; independently verify removal, fail on leaks |

The probe may use a small isolated custody/checkpoint fixture, but that
fixture **must invoke production host-side lease/fencing authority as part of
performing its durable writes, atomically with the protected mutation**. The driver must independently query what
was actually written. It may not implement authority in the test adapter,
manufacture an Attempt, replace Ecotone with an in-memory queue, or infer
success from a returned status alone.

This bounded probe cannot certify the full Vault's OCFL atomic commit or
external plugin effects. A later real Preservation/Vault integration must
repeat the custody/commit ownership proof at its actual publication boundary.
Do not claim that integration is complete on the strength of the probe.

## Failure-injection requirements

- Competing claims require two real processes overlapping at the **atomic**
  database claim decision; sequentially invoking two methods is insufficient.
  A deliberate extra physical invitation to the same Job is allowed, but a
  fake extra logical Job is not.
- Redelivery is an actual native transport receipt with visibility expiry,
  not a direct call to `handle()` pretending to be a worker.
- Bound test time using a supported short lease, or a timeout sufficient for
  the existing 60-second lease, and observe actual PostgreSQL time for expiry/renewal. Never simply rewrite
  `lease_until` and claim to have proven renewal or liveness. Renew with enough
  database-observed margin beyond the original deadline for the later consumer
  and checkpoint actions; renewing immediately after claim gives a fragile window.
- For stale workers, keep A alive after B claims. Observe rejection at the
  host-side checkpoint, custody, result and terminal boundaries, **not**
  just a final DB update that fails after an unguarded side effect.
- The host write must couple authority checking and authoritative mutation.
  `host.race_write` pauses after any detached/preflight authorization and before
  the actual guarded mutation. If production uses one atomic conditional write,
  pause immediately before that statement (or before a transaction that holds
  the same ownership lock through authorization and mutation). Do not split a correctly atomic
  production operation just to insert a test barrier. A must physically remain
  alive and resume its own pending write after B owns the Job. Independently
  inspect durable writes; a driver comparing supplied identities proves nothing.
- PostgreSQL time is authoritative at the protected mutation/renewal decision,
  not a cached deadline, PHP clock or transaction-start `now()`. A mutation that
  legitimately linearized while authority was valid need not be retroactively
  erased because its response arrived after expiry. Never permit a detached
  successful check to authorize a later mutation after revocation.
- Retain A's identity/history without asserting an exact outcome label or row
  order. Supersession proves loss of authority, not physical worker death,
  handler failure or successful completion. Diagnostic observations of stale
  processes may be retained separately from authoritative execution state.
- A valid Attempt can renew only while it owns an unexpired claim. Renewal
  must not change logical Job identity, create a new Attempt, or reset work.
- The custody scenario must retain previously verified state across a real
  process crash, detect attempted publication before reconciliation, and prove
  exactly one authoritative adopted result after reconciliation. Preserve
  underlying acknowledged custody, do not delete and reconstruct it.
- Use stable operation/coverage identities where appropriate. Attempt IDs
  are **not** suitable external-effect deduplication keys across retries.

## Independent verification expectations

Before claiming OPS-C03 is implemented, an independent reviewer should
repeat the nine positive contracts and deliberately break at least these
boundaries: remove atomic claim exclusion, equate transport visibility with
Job ownership, allow stale renewal, permit stale checkpoint writes, permit
expired terminal completion, use a skewed host clock for renewal, split authorization
from mutation, disable real renewal, skip custody reconciliation, allow both racing
operations to succeed, reuse a pre-wait database timestamp, and let a stale handler
failure change the Job or publish a retry. Each negative control must show the **specific intended
violation**; a fixture error or timeout cannot count as success.

Keep source/mutation restoration reproducible and evidence sanitized. Include
fresh-process and lease/Attempt identities, actual PostgreSQL ownership
observations, independently recorded host writes and proof that no fixture
resources leaked. Do not mutate active user data.

## Boundaries for later contract groups

- Remote broker differences and exact transport keepalive mechanics beyond
  the currently configured native PostgreSQL channel.
- Whole worker-helper process containment and actual user cancellation
  (OPS-C05), global measured capacity admission (OPS-C08).
- Every production Vault/OCFL checkpoint and commit integration, and provider
  or Broadcast external-effect idempotence.
- Comprehensive lifecycle, retries, history and UI state (OPS-C04/C07/C09).

Stashd's established Tempest-first policy applies to implementation. This
specification neither authorizes a second execution framework nor proposes
`ExecutionContext` as an authority token. Its current deadline is descriptive;
the host must check the **live current claim** before granting mutations.

## Review against merged Ecotone (2026-10-31)

Baseline: PR #80 merged into `dev` at `ddc38eb5`. Its native application bootstrap,
shared DBAL owner, atomic Job/invitation admission and real consumers supersede the
older vault notes' Messenger mechanisms. The vault Operations contract OPS-C03 and
OPS-PSC04, and reconciliation PSC-OPS-04, retain their domain safety requirements.
OPS-C01 identity/coalescing/priority and OPS-C02 durable delivery remain unchanged.
Current `JobRuntime` has conditional claims and completion, but a fixed 60-second
lease, no renewal, and no general host write fence. Those partial facilities do
not satisfy these nine contracts.

The test surface remains one action adapter, not production services/interfaces.
There is no default implementation or new dependency. Existing Ecotone fixtures
can supply restricted PostgreSQL installations and independent consumers; do not
reuse their `expire()` helper, which changes both visibility and Job lease. Native
visibility may be advanced independently for redelivery. Execution lease tests
must wait for actual database expiry. A short lease can be configured by a future
production-supported test setting; this branch does not invent one. Alternatively
use a bounded timeout sufficient for the existing 60-second lease. Hold automatic
renewal where a failure test requires expiry; do not mistake a paused executor for
a dead process. `worker.try_redelivery` must finish its observation while the
relevant renewed/original lease is still valid, or fail as an invalid fixture.

Custody remains a bounded C03 host-boundary proof: real bytes and digest-verified
retained receipts cross real production authorization, then a crash and recovery
must preserve those receipts and gate result adoption on actual reconciliation.
The adapter may pause before normal reconciliation to exercise early publication;
it must not require production to expose a public `reconcile_custody()` method or
an error string. Automatic reconciliation during real recovery is equally valid.
A test ledger is observation/storage only, never an implementation of authority or
an in-memory flag granting publication. Full OCFL journal repair, Vault locking,
version identity and archive crash windows remain Preservation's integration gate.

Recommended C03 boundary: atomic current Attempt admission, database-time expiry
and renewal, coupled authority checks at Core host writes and terminal transitions,
and the recovery/reconciliation handoff. Keep message delivery, busy deferral,
retry/dead-letter handling with Ecotone. Do not add cancellation, resource
scheduling, a lifecycle redesign or arbitrary external-effect exactly-once claims.

Specification-owner decisions are **approved**:

1. C03 owns the bounded production-fenced custody-reconciliation handoff.
   Full Vault/OCFL integration remains Preservation's responsibility.
2. Authority is established at the atomic protected mutation boundary, never
   by a detached earlier check. A legitimately committed mutation remains valid
   even if its response arrives after lease expiry.

The two additional contracts cover renewal/takeover serialization and stale
handler failure. They preserve the original seven contracts unchanged. They do
not exhaust every database interleaving, host-clock skew or network partition;
implementation verification must still add targeted negative controls.

## Harness status and verification limits

All nine cases must be discovered and report **INCOMPLETE** with no installed
production-backed driver. Pest can exit zero with incomplete cases; that exit code
is not an implementation acceptance gate. Inspect the incomplete count/JUnit report.
A configured invalid driver must fail. Cleanup runs from teardown even after failed
assertions; if driver cleanup fails, retain its private workspace/manifest so that
resource identities are available for repair rather than deleting the evidence.
A driver must make disposal idempotent after partial setup and assert child reaping
and private database/role removal. Until it exists, only local workspace cleanup is
exercised; real C03 worker/database cleanup and negative controls are unproved.

Reviewed nine-contract validation (2026-10-31):

- Canonical `lerd composer qa`: **480 passed, 42 incomplete, zero failures**,
  four processes. Existing Preservation contributes 33 incomplete contracts;
  C03 contributes nine. Existing Operations/Preservation behavior is retained.
- Mago format/lint are clean; analysis retains the same three pre-existing
  Records & Archives help diagnostics.
- Focused `STASHD_TEST_PROCESSES=1 ./bin/test.sh
  tests/Unit/FencingFixtureTest.php tests/Acceptance/OperationsFencing`:
  two fixture safety tests pass (seven assertions), nine C03 cases are
  INCOMPLETE (zero assertions). JUnit confirms nine discovered contracts
  without errors or failures. The race contract requires both orderings;
  it is not parameterized into a separate case for each winner.
- A byte comparison confirms the seven original contracts are unchanged.
  This continuation changes only this README and `AttemptFencingTest.php`.
  No production source, driver implementation, dependencies or unrelated
  worktrees changed. Existing missing/invalid-driver and manifest-retention
  fixture safety checks pass; their fixtures are unchanged.
- Real C03 authority, renewal, stale-failure handling, process/database cleanup
  and negative controls remain unproved without a production-backed driver.
  No simulated authority was introduced to make these cases pass.

## Concurrent renewal versus takeover

This is one acceptance contract with two separately scoped Jobs. **Both orders
must run**, not whichever winner scheduling happens to produce. `first_mutation`
is a test barrier order, never an instruction to manufacture a result or directly
update ownership. Use real A renewal and a separate B native Ecotone consumer,
with actual overlapping database operations at the ownership decision. Observe
real PostgreSQL lock/wait contention where applicable; a guarded conditional write
may also reject a contender immediately without waiting. Do not prescribe a new
production lock solely to force a wait. A sequential pair of calls is insufficient.
Independent PostgreSQL sessions observe the Job, lease and historical Attempts;
worker responses alone cannot supply those observations.

- **Renewal first:** A renews while still valid. Keep B contending, and let B's
  actual guarded claim decision occur after the original deadline but before the
  renewed deadline. B must re-read the renewed lease, acquire no Attempt and
  preserve A's identity/history. Give the renewal enough margin for real processes.
- **Takeover first:** Start A's renewal request while A is alive (it may have passed
  an earlier preflight), but hold it before its protected mutation. Allow the
  database clock to pass the original deadline; B claims a new Attempt, then A's
  contending renewal proceeds and must recheck current identity and time. Never
  let an earlier timestamp or cached context revive A's old lease.

Do not pause between authorization and mutation inside a correctly atomic locked
unit. Place a barrier before that unit or use database contention to hold it as a
whole. Never change the production transaction ordering to satisfy this harness.
`renewal_decision_at` and `takeover_decision_at` are PostgreSQL times at the actual
protected decisions after any lock wait, independently evidenced from the real
statements/transactions, not PHP clock readings or SELECTs taken before blocking.
`authority_snapshots` contains independent database observations at `before`,
`after_first` and `after_both`; each includes `authoritative_attempt_ids`. No snapshot
may contain two authoritative Attempts. The surviving owner's checkpoint must also
succeed, and superseded A's checkpoint must fail. A renewal creates no Attempt;
takeover retains A and creates exactly one distinct B Attempt in the same delivery
generation. Read the persisted Job lease and history independently after both
operations settle, with neither executor completing or autonomously renewing in
that bounded observation window.

Snapshots alone cannot establish every possible interleaving. Database competition
evidence, real write controls and implementation negative controls must establish
that exclusion holds at the protected mutation itself. A timestamp comparison or a
test-only authority ledger cannot substitute for that proof.

## Stale handler failure after takeover

Keep A physically alive until B holds an unexpired lease. Snapshot the authoritative
Job fields, B's persisted Attempt, both Attempt identities/start times and immutable
native publication history. Hold B paused with sufficient lease margin and no
unrelated completion/renewal during this observation window. Then resume **A's
actual executor**, throwing the requested identifiable handler error. Do not call
a failure repository method directly or invent an exception receipt in the driver.
Observe the real failure-transition decision and let Ecotone settle that delivery;
only then take the independent database/publication snapshots again.

A's stale failure must leave B's authoritative Job state, claim/deadline and Attempt
unchanged. The domain failure budget and delivery generation cannot increase, and
there can be no new native retry publication attributable to that failure. Audit
actual committed enqueue INSERTs in the disposable fixture so even a retry later
consumed/deleted remains visible. Reading pending queue length alone is not proof;
acknowledging A's original delivery may legitimately remove that row. This audit is
observation only and implements no lease, failure policy, queue or authority.

Retain both historical Attempt IDs and A's original start time; never manufacture
a third execution, erase prior history or mark A completed. A real late error may
be retained as an Attempt diagnostic without becoming a new authoritative Job
failure. Do not require an exact superseded outcome label or force B's history to
change to accommodate A's error. A stale transition rejection is an expected fenced
outcome, not a new domain failure or an excuse for an infrastructure retry loop.
B's checkpoint must remain usable afterward.

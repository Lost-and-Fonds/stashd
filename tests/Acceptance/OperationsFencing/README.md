# OPS-C03 — execution Attempts, leases and fencing

These are **independently authored, outside-in Pest acceptance contracts**, based
on the agreed Stashd Core vNext OPS-C03 and OPS-PSC04 requirements. They follow
the merged Operations admission and durable-delivery slices (PRs #74 and #78).

This branch is a **tests-only specification**. The new tests intentionally
report INCOMPLETE until a real driver exists, and they must not be rewritten
to make an implementation green.

## Safety contract

A Messenger envelope is an invitation to execute, **not** ownership of a Job.
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

## Seven required scenarios

| Scenario | Failure / overlap | Protected observation |
| --- | --- | --- |
| Competing initial claims | Two live workers meet at the real claim boundary | Only one persisted Attempt enters execution |
| Redelivery with valid owner | Native transport visibility expires while Job lease is still valid | Another delivery cannot take ownership |
| Live expired worker | A remains alive past its DB lease; B recovers in the same message generation | B has a new Attempt; A is interrupted and cannot renew |
| Host-boundary fencing | A attempts checkpoint, custody and result writes after B takes over | Every A write is rejected; B's valid write succeeds |
| Expired without successor | A attempts host mutation and Job completion after expiry, before B claims | Expiry alone revokes write and terminal authority |
| Long-running renewal | A renews before expiry; time passes beyond its original deadline | B cannot steal the still-valid renewed claim |
| Custody before recovery | A persists verified custody then crashes; B recovers | B must reconcile retained custody before publication |

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
PostgreSQL/Messenger installation and separately managed worker processes
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
| `job.read` | `job_id` | Inspect current persisted Job including `state` |
| `worker.compete_for_claim` | `job_id, workers` | Overlap two actual worker processes at a database claim boundary, prove both attempted it, and return `observed_competing_processes`, `entered_executor_attempt_ids` |
| `worker.start_paused` | `job_id, worker` | Actual worker claims Job and enters real executor, then blocks before completing; return `job_id, attempt_id, delivery_generation, lease_expires_at` |
| `worker.try_redelivery` | `job_id, worker` | Deliver an eligible *native* envelope through a separate worker; return `received_native_envelope, acquired_attempt` |
| `worker.recover` | `job_id, worker` | Let a fresh worker recover the expired Job through real claim path; return `job_id, attempt_id, delivery_generation` |
| `worker.try_complete` | `attempt_id` | Let the selected real executor attempt its normal terminal transition; report `accepted` based on persisted status, not merely an exception type |
| `worker.crash` | `attempt_id` | Kill and reap the actual worker process; return `terminated_process` only after verified termination |
| `worker.release_all` | `job_id` | Release/stop test-controlled workers safely; fail on leaked children |
| `attempts.read` | `job_id` | Return persisted `attempts` list, `current_attempt_id` (or null), and each historical Attempt's `outcome` |
| `transport.expire_delivery` | `job_id` | Advance a real delivered Messenger envelope to redelivery eligibility, **without** touching the Job lease |
| `lease.await_expiration` | `attempt_id` | Observe PostgreSQL clock past actual lease deadline; return `database_lease_expired, original_worker_alive` |
| `lease.renew` | `attempt_id` | Attempt renewal through the actual authoritative lease path; return `accepted, new_deadline_later` if accepted |
| `lease.await_original_deadline` | `attempt_id, original_deadline` | Wait for DB time to pass original deadline while renewed lease remains valid; return `original_deadline_passed, renewed_lease_still_valid` |
| `host.try_write` | `job_id, attempt_id, kind, value` | Invoke the actual Core-gated host path for a `checkpoint`, `custody`, or `result`; return `accepted` and rejection `reason` |
| `host.try_publish` | `job_id, attempt_id, coverage` | Attempt authoritative result adoption/commit through real host protection; after recovery require reconciliation |
| `host.reconcile_custody` | `job_id, attempt_id` | Validate durable acknowledged custody, return verified `custody_keys` in canonical order as `verified_custody_keys` |
| `host.records` | `job_id` | Independently read persisted `checkpoints`, `custody`, `results`; no unique constraint or projection hiding duplicate writes |
| `fixture.dispose` | none | Kill/reap all children, remove private credentials, database, role, transport data and host fixture |

The probe may use a small isolated custody/checkpoint fixture, but that
fixture **must invoke production host-side lease/fencing authority before
performing its durable writes**. The driver must independently query what
was actually written. It may not implement authority in the test adapter,
manufacture an Attempt, replace Messenger with an in-memory queue, or infer
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
- To keep tests bounded, use a supported **short test lease duration** and
  actual observed PostgreSQL time for expiry/renewal. Never simply rewrite
  `lease_until` and claim to have proven renewal or liveness.
- For stale workers, keep A alive after B claims. Observe rejection at the
  host-side checkpoint, custody, result and terminal boundaries, **not**
  just a final DB update that fails after an unguarded side effect.
- The host write must couple authority checking and authoritative mutation,
  preventing a check-then-write race during B's claim.
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
repeat the seven positive contracts and deliberately break at least these
boundaries: remove atomic claim exclusion, equate transport visibility with
Job ownership, allow stale renewal, permit stale checkpoint writes, permit
expired terminal completion, disable real renewal, and skip custody
reconciliation. Each negative control must show the **specific intended
violation**; a fixture error or timeout cannot count as success.

Keep source/mutation restoration reproducible and evidence sanitized. Include
fresh-process and lease/Attempt identities, actual PostgreSQL ownership
observations, independently recorded host writes and proof that no fixture
resources leaked. Do not mutate active user data.

## Boundaries for later contract groups

- Remote broker differences and exact transport keepalive mechanics beyond
  the currently configured PostgreSQL/Doctrine path.
- Whole worker-helper process containment and actual user cancellation
  (OPS-C05), global measured capacity admission (OPS-C08).
- Every production Vault/OCFL checkpoint and commit integration, and provider
  or Broadcast external-effect idempotence.
- Comprehensive lifecycle, retries, history and UI state (OPS-C04/C07/C09).

Stashd's established Tempest-first policy applies to implementation. This
specification neither authorizes a second execution framework nor proposes
`ExecutionContext` as an authority token. Its current deadline is descriptive;
the host must check the **live current claim** before granting mutations.

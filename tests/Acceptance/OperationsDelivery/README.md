# OPS-C02: durable-delivery acceptance contracts

These seven tests are an independently authored, tests-only specification for
**OPS-C02 / OPS-PSC03** in Stashd Core vNext. They are based on the agreed
Operations contract and reconciliation notes. This is the next outside-in
contract group after the merged Work-admission implementation in PR #74.

**The tests are not an implementation design, a second Messenger adapter, or a
claim of passing recovery behaviour.** They must not be rewritten to fit a
convenient implementation. A future implementer should satisfy them using the
real Job/outbox transaction, Messenger transports and workers.

## What the tests prove when backed by a real driver

1. Rejection of the outbox insert rolls back the Job admission as one transaction.
2. Killing admission before commit rolls back both Job and delivery intent;
   a later legitimate request can still be admitted.
3. Killing/restarting the application after commit but before relay leaves a
   recoverable Job and outbox entry; real workers eventually execute once.
4. An unavailable forwarding destination does not erase accepted intent or
   fabricate completion; retry/recovery after restoration executes the same Job.
5. A relay killed after forwarding but before acknowledgment may forward
   twice; the logical Job still produces one effect in that window.
6. A worker killed after persisted completion but before transport acknowledgment
   may receive redelivery; a completed Job cannot start another Attempt/effect.
7. Two distinct accepted Jobs still complete when relay deliveries are forwarded
   in reverse order. The broker does not determine authoritative Job meaning.

A "single effect" in these scenarios means one **independently persisted probe
effect** under the controlled failure window. This is deliberately *not* a
guarantee of exactly-once arbitrary external effects. OPS-C03 must separately
establish fencing of live expired workers and host-side authoritative operations;
external systems require their own idempotency and reconciliation.

These tests focus on the configured local PostgreSQL/Doctrine path. An
external-broker integration must independently repeat the relevant outage,
acknowledgment and duplicate-delivery scenarios against a real remote broker;
faking remote availability with an in-memory flag is not that proof.

## Driver installation and isolation

Define an autoloadable
`Tests\Support\OperationsDelivery\DurableDeliveryDriver` implementation and
set `STASHD_OPERATIONS_DELIVERY_DRIVER` to its class name in the *test
environment*. Unlike the Work-admission driver, none is installed yet. Without
one, all seven new tests intentionally report **INCOMPLETE**, not passed.

Every `run(action, arguments)` receives a private `workspace` path and a
fresh driver instance. All state, fault controls and process handles required
across actions must be recoverable from the private installation. Discarded
driver objects must not result in success from remembered in-memory state.

The driver must provision and tear down a **disposable** PostgreSQL database or
schema, an isolated real transport namespace, a test-only probe operation and
bounded real worker/relay processes. Refuse application or production
credentials. Pass worker credentials with restricted authority. Even after test
failure, stop every child and remove the fixture. Use bounded timeouts; a hang is
a failure, not an incomplete capability.

### Test-only action dictionary

These are observation/control operations; they do not prescribe production
service classes or method signatures.

| Action | Inputs (beyond workspace) | Required observation/control |
| --- | --- | --- |
| `probe.prepare` | `name` | Durably register a disposable Work scope; return `scope_id` |
| `work.request` | `scope_id, mode, observation, priority` | Real admission; return `job_id` |
| `work.request_with_expected_failure` | `work` | Invoke real admission under an armed fault; return `failure_kind` only after verifying the actual outbox write was rejected; unexpected failures fail the test |
| `jobs.for_work` | `scope_id, mode` | All persisted historical Jobs as `jobs`, not reconstructed delivery receipts |
| `jobs.read` | `job_id` | Persisted `job` with truthful `state` and `attempt_count` |
| `probe.effects` | `scope_id, mode` | `job_ids` in independently persisted effect order; no Job uniqueness constraint hiding duplicates |
| `delivery.outbox_for_work` | `scope_id, mode` | `job_ids` from actual retained outbox envelopes (not logical Job rows) |
| `delivery.forwarded_for_job` | `job_id` | `job_ids` represented by actual destination-transport records |
| `delivery.recoverable_intents` | `job_id` | `job_ids` backed by actual still-recoverable outbox or failure-transport records |
| `delivery.arm_fault`, `delivery.release_fault` | `point` | Activate/deactivate a real fault at `outbox-insert-rejected` or `forward-target-unavailable`; not just a test-only boolean |
| `delivery.relay_once` | none | Attempt forwarding using the real relay; return actual `outcome` (for the injected case: `target_unavailable`) |
| `delivery.run_to_boundary` | `boundary`, optionally `work` or `job_id` | Start a real child and prove it reached exactly `admission-before-commit`, `relay-forwarded-before-ack` or `worker-completed-before-ack`; return `reached` |
| `delivery.kill_at_boundary` | `boundary` | Kill the actually paused child, prove non-graceful termination and return `terminated_process` |
| `delivery.restart` | `stop` | Dispose current relay/worker processes and reinstantiate real runtime connections without erasing durable data |
| `delivery.expire_visibility` | `job_id, transport` | Make an actual unacknowledged native Messenger delivery eligible for redelivery after the killed process; do not manufacture a new Job |
| `delivery.retry_stalled_forwarding` | `job_id` | Recover an outbox/failed-envelope via real transport recovery without creating another Job |
| `delivery.forward_in_order` | `job_ids` | Actually forward these outbox messages in the requested order through the configured transport; report observed physical forwarding order |
| `delivery.run_until_settled` | none | Drive actual relay/workers until eligible work settles or fail on timeout; do not claim success on mere publication |
| `fixture.dispose` | none | Clean up all isolated processes, transport rows, database resources and fault controls |

The driver may add other private operations but **may not implement a parallel
fake Job store, queue, executor, ack mechanism, or completion ledger**.

## Failure injection rules

- **Admission-before-commit:** Block a real transaction immediately before
  commit, establish its live transaction/lock state from PostgreSQL, then kill
  its process; no committed Job or outbox entry may remain.
- **Outbox rejected:** Make a real outbox insert fail inside the shared
  admission transaction; check no Job survived using an independent connection.
- **After commit before relay:** Stop processes; inspect the committed Job and
  native outbox row before any relay executes. Restart from fresh processes.
- **Forward target unavailable:** Fail an actual transport write, not merely a
  simulated network-status value. Recovery may require replay of a retained
  failed envelope; prove that such a retained durable record exists.
- **Relay forwarded before ack:** Confirm an actual target-transport record
  exists while the outbox remains unacknowledged, kill the relay, then allow
  real redelivery. The destination may receive the same invitation twice.
- **Worker completed before ack:** Confirm completed Job and independently
  stored effect before killing the worker; redelivery of that same message
  must not create another Attempt.
- **Reverse order:** Observe real physical forward order. Sorting the returned
  IDs or invoking Job handlers directly is not acceptable evidence.

A missing capability should raise `IncompleteTestError`. A malformed driver,
unsafe configuration, unsupported fault setup in an otherwise installed
capability, timed-out boundary, unexpected database error, or failed assertion
must be reported as **FAILURE**. No fake green checks.

## Required adversarial verification after implementation

The independent verifier must deliberately sabotage controls to prove the tests
fail for the intended reasons. Examples: split Job/outbox transactions,
acknowledge a relay message before forwarding, drop the durable outbox on
restart, duplicate a completed Job Attempt, conceal a repeated effect, or
implicitly sort out-of-order deliveries. Restore every mutation before running
the positive suite.

Before treating OPS-C02 as complete, collect per-case evidence from disposable
PostgreSQL transactions, real relay/worker process IDs, crash exit states,
Messenger envelope/transport observations, and independent effect rows.
Document which scenarios remain backend-specific or unproven.

## Boundaries deliberately deferred

- OPS-C03: live expired-worker fencing, claim renewal and authoritative host
  operations; no claim of exactly-once arbitrary external effects.
- OPS-C04 onwards: full lifecycle, pause/cancel, fairness, resource budgets,
  UI observability, old queued-message compatibility.
- EventManagement: use the merged event vocabulary when implementation arrives;
  no new event type system is authorized by these tests.
- No new production transport or database implementation belongs to this PR.

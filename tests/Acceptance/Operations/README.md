# Operations acceptance contracts

These are outside-in executable Pest contracts, derived from the agreed Stashd
Core vNext Operations decisions. **They are not an API proposal.** They must not
be rewritten to make a convenient implementation green.

The source of requirements is the Obsidian specification's Operations contract
(OPS-C01 through OPS-C10), its Process Success Contracts (OPS-PSC01 through
OPS-PSC12), and the agreed test-author / implementer / verifier separation.

## Scope of this first group

`WorkAdmissionTest.php` exercises **OPS-C01 and the queued-priority part of
OPS-C06**:

1. Eight truly concurrent equivalent requests must return one durable Job;
   actual execution must produce one observable effect.
2. Refresh and Complete modes have separate Work identities even for one scope.
   Duplicates coalesce only inside their own mode.
3. A queued Job promoted by an interactive request keeps its Job ID, is selected
   ahead of older routine work, and executes once despite obsolete deliveries.
4. A fresh, legitimate request after terminal completion creates another Job
   while preserving the prior Job and both execution records.

These are deliberately stronger than checking that a Job was inserted into SQL.
They catch a globally unique idempotency key, work identity that ignores the
operation mode, check-then-insert races, and priority updates that never affect
queued execution. The suite still needs a real driver and deliberate negative
controls before anyone can claim those faults were observed failing.

### Test-only operation dictionary

Provide an autoloadable class implementing
`Tests\Support\Operations\OperationsDriver` using
`STASHD_OPERATIONS_ACCEPTANCE_DRIVER`. Each call receives the same private
`workspace` path, but a fresh driver object. All state required to pass must
survive that object being discarded.

The following actions define *observations and test controls*, not PHP service
names or a mandated production class structure:

| Action | Arguments beyond `workspace` | Observed result / requirement |
| --- | --- | --- |
| `probe.prepare` | `name` | `scope_id`: register a durable, isolated test Work scope with two modes (`refresh`, `complete`) using real Core execution paths |
| `work.request` | `scope_id`, `mode`, `observation`, `priority` | `job_id` of the actual admitted/joined Job; `observation` denotes the input snapshot, not another Work identity |
| `work.request_concurrently` | `requests` array | `receipts` in input order, each containing `job_id`; start independent processes/transactions together, not sequential calls |
| `jobs.for_work` | `scope_id`, `mode` | `jobs`: all durable historical Jobs, each with `id` and truthful `terminal` Boolean |
| `jobs.read` | `job_id` | `job`: persisted Job with `state` (including `completed`), `desired_priority`, `terminal` and count of real execution Attempts (`attempt_count`) among its fields |
| `work.execute_next` | none | Drive the real relay/worker path for exactly one eligible Job, returning its `job_id` from observed execution |
| `work.execute_all` | none | Drive real workers until all currently eligible work has settled; fail on deadlock/timeout, do not declare success after sending only |
| `probe.effects` | `scope_id`, `mode` | `job_ids`: one actual durable effect record per execution, not constructed from Jobs or messages |
| `fixture.dispose` | none | Stop isolated workers and remove disposable transport, database and probe resources even after an assertion fails |

The test-only probe should be the smallest deterministic operation exercisable
through real Stashd Work admission and execution. It may be registered through
an acceptance-only extension, but it must **not** implement a second fake queue,
Job store, worker or scheduler. Its `scope_id` stands for a configured work
subject; a later end-to-end Discovery test must prove that real StashInput modes
map to the same distinct Work semantics. The words `request`, `execute_next`
and `probe` here do not prescribe production method names.

### Realism and isolation rules

- Every scenario needs a disposable PostgreSQL schema, transport namespace,
  registered probe and controlled workers. Never reuse a developer's working
  Vault, database, queue or credentials. The driver must refuse unsafe setup.
- Concurrent requests must actually overlap at the transactional admission
  boundary, using an explicit barrier or equivalent mechanism. Spawning them
  sequentially would make the key race test meaningless.
- Effects must be recorded durably and independently of a Job's `completed`
  status. A worker reporting completion does not prove a side effect happened.
- A promoted queued Job must beat unrelated older routine work through the
  scheduler/transport, not through a test adapter sorting the results itself.
- Unexpected setup, process and database errors fail tests. Only a genuinely
  unimplemented application capability may raise `IncompleteTestError`.
- Without an installed real driver the suite reports **INCOMPLETE**, not green.
  This is deliberate: acceptance tests precede their production implementation.

## Remaining department contract groups

These are **not yet implemented acceptance tests**. Work on one bounded group at
a time and get specification-owner review before the implementer begins:

| Contract | Adversarial acceptance evidence still required |
| --- | --- |
| OPS-C02 — durable delivery | Reject native queue insert; crash before/after Job commit and receipt/completion; retry/dead-letter real infrastructure failures; prove retained intent and safe completed redelivery |
| OPS-C03 — Attempts and fencing | Kill/partition Worker A, let lease expire, allow B to recover; A stays physically alive but cannot commit or publish effects |
| OPS-C04 — lifecycle | Persist Attempt starts/ends and interrupted history across process restart; no invented completion after crash |
| OPS-C05 — pause/cancel | Distinguish accepted pause from achieved pause, stop descendant helpers, retain acknowledged custody |
| OPS-C06 — fairness | Reprioritize during dequeue, protect commit/recovery, avoid starvation over a sustained mixed-priority load |
| OPS-C07 — retries | Exhaust a finite error budget, honour provider-wide cooldown, retain history and retry the same logical Job |
| OPS-C08 — resources | Admit 80,000 Jobs without 80,000 processes; enforce measured host headroom and protected control capacity |
| OPS-C09 — observability | Match displayed queued/running/paused/failed and worker counts to independent persisted/process observations |
| OPS-C10 — evolution | Restart with weeks-old queued messages after a compatible deployment; recover without losing Jobs |

An independent verifier must still challenge each implemented group. Neither a
green test suite nor the original test author can certify the whole department.

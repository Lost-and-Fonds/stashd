# Durable Operations delivery

OPS-C02 specifies durable intent and honest Job execution, not an intermediate
relay. Ecotone stores invitations directly in PostgreSQL's `enqueue` table in the
same physical transaction as admission. There is no local forwarding hop.

`tests/Acceptance/EcotoneDelivery/NativeDurabilityTest.php` is the executable native
fault contract. `tests/Acceptance/OperationsDelivery/DurableDeliveryTest.php`
observes actual reordered effects. OPS-C01 remains in its original acceptance
file with its assertions unchanged. The migration and retired drivers are
reviewable at commit `56026a2`; no old transport implementation remains executable.

## Correspondence with retired Messenger observations

| Previous executable test | Reliability obligation | Current executable evidence |
| --- | --- | --- |
| OPS-C02: outbox insert rejection rolls back the accepted Job | Admission and invitation commit atomically | NativeDurability: queue insertion rejection; Installation nested rollback/savepoint cases; WorkAdmissionAtomicity: rejected initial enqueue and promotion |
| OPS-C02: killing admission before commit | No partial durable intent; identity remains admissible | NativeDurability: death at the precommit PostgreSQL barrier, no Job/invitation, successful readmission |
| OPS-C02: committed Job survives restart before relay | A committed Job must retain executable intent | NativeDurability: committed intent survives fresh application and native consumer |
| OPS-C02: unavailable forwarding destination | Infrastructure failure must not fabricate domain success; intent is recoverable | NativeDurability: rejected actual claim update, finite native retries, retained dead letter, native CLI inspection and replay; failed replay preserves the original |
| OPS-C02: relay forwarded before acknowledgement death | Receipt/acknowledgement loss cannot lose accepted Work or duplicate completion | Local forwarding disappears. NativeDurability's receipt/claim death proves recovery before completion; its completion-before-ack death proves safe recovery after completion |
| OPS-C02: completed Job redelivery after lost worker acknowledgement | Persisted completion cannot start another Attempt/effect | NativeDurability: actual queue DELETE barrier, SIGKILL and server abort, independently retained row, redelivery, one Attempt/effect |
| OPS-C02: changed forwarding order | Physical delivery order cannot define authoritative outcomes | OperationsDelivery: second admitted Job executes first by native priority, both complete with exact independent histories/effect order; NativeDurability also delivers obsolete generations ahead of current ones |
| Integration JobsTest: admitted once and relayed to worker | Keyed admission, real execution, duplicates, mismatched payload rejection, rollback | JobsTest: keyed Jobs admitted once through concurrent fresh applications, native duplicate publication/consumption, completed history, rejected queue write |
| Integration JobsTest: relay/worker interrupted acknowledgement | Retained executable intent and no repeated completed effect | NativeDurability: receipt/claim death and completion-before-ack death; there is no relay acknowledgement to reproduce |
| Integration JobsTest: killed worker claim expiry | Recovery may close an interrupted Attempt and finish the same Job | NativeDurability: receipt/claim death with actual lease/visibility expiry |
| Integration JobsTest: failed relay messages recover | Exhaustion is inspectable and replayable without inventing success | NativeDurability: actual native dead-letter and CLI replay/rejected-replay observations |
| Integration JobsTest: completion database failure | Completion failure does not consume handler-failure budget | JobsTest: completion database failure, interrupted/completed history, two observable effects exposing the unprotected effect/completion window |
| Integration JobsTest: duplicate old deliveries cannot consume retries | Obsolete invitations cannot start another failing Attempt | JobsTest: duplicate old deliveries, exact generation/failure/Attempt observations |
| Integration JobsTest: expired claims and failed retry enqueue | Retry state and replacement invitation roll back together; Attempt failures have their own budget | JobsTest: real retry insertion rejection, unchanged running claim/failure/generation, retained original invitation, recovery to one interrupted plus three failed Attempts |
| WorkAdmissionAtomicityTest's outbox checks | Queued promotion and admission are atomic; running coalescence does not restart | Same tests now reject native enqueue writes and inspect original Job revision/invitation; assertions retain priority, generation, payload and claim semantics |
| WorkExecutionContextTest's relay calls | Executors receive actual persisted claim metadata on each Attempt | Same context assertions, now real application/fresh native consumers, exact persisted token/generation/deadline matches |

The old retry-counter fixture seeded eight starts without eight historical Attempt
records. The replacement does not fabricate history: it observes four actual
starts, one interrupted and three failed, with exactly three domain failures.
The original domain failure-budget obligation is stronger with those observations.

## Fault observations and limits

Each native fixture owns a generated PostgreSQL database, restricted role and
private directory. Credentials arrive on child stdin. Application migrations,
explicit queue provisioning, Jobs, native consumers and domain executors are real.
Effects use a separate PDO and have no uniqueness constraint hiding duplicates.
Process deadlines, actual signal status/reaping, database/role removal and private
directory removal are checked. Faults never target an application database.

Admission death blocks the real COMMIT on a deferred PostgreSQL trigger; the test
ends the client and blocked backend before checking rollback. Completion-before-ack
blocks the real queue DELETE, independently observes completed state and the
unacknowledged invitation, then kills/reaps the worker and aborts that backend.
A retained queue row is required before redelivery. Client death alone cannot
promise rollback once PostgreSQL has received COMMIT.

Crash tests explicitly move the real persisted visibility and lease deadlines into
the past after killing the owner. The actual configured deadlines are also compared
in PostgreSQL: native visibility and Job lease are both 60 seconds. These are
bounded local restart proofs, not power-loss, remote broker or network-partition
claims. Busy duplicate deliveries retain a delayed invitation without consuming
handler-failure budget; unrelated Work completes and polling before the deadline
adds no Attempt or effect.

No test promises exactly-once arbitrary external effects. An effect can precede a
failed completion write; a recovered Attempt can repeat it. OPS-C03 live stale-worker
fencing and lease renewal are not implemented here. Department reconciliation
remains responsible for unmet coverage after a Job terminates.

## Adversarial verification

```sh
python3 -B -m unittest discover -s tools/verification -p 'test_*.py'
python3 -B tools/verification/ops_c02_negative.py
python3 -B tools/evaluations/installation-negative.py
```

The native verifier replaces the obsolete relay verifier. It requires a passing
ten-case baseline, nine targeted mutations and a passing restored native suite.
The nesting verifier checks premature physical commit, a separate physical
connection and bypassed DBAL nesting. A matching first assertion, exception class
and independent observations are required. Setup errors, wrong assertions,
timeouts, additional diagnostics and teardown errors cannot count as detection.
Both tools preserve exact pre-existing uncommitted bytes; run them without another
test, formatter or editor changing their mutation targets.

# OPS-C02-E: transport-neutral durable Operations acceptance

This additional contract does not replace or edit OPS-C01 or OPS-C02. It specifies
product outcomes using native Ecotone PostgreSQL invitations, not a relay model.
Setup errors, unexpected database failures, process deadlines and absent barriers
are failures. A negative control counts only at its named observable assertion.

## Mandatory invariants and independent observations

1. **Atomic admission:** reject an actual channel INSERT; independent SQL observes
   no Job and no invitation. Reject a state mutation after enqueue within the same
   application transaction; neither survives. Readmission succeeds after faults.
2. **Death before commit:** a deferred database trigger blocks COMMIT after both
   writes. Independently observe the active backend and no visible rows, SIGKILL
   and reap the application process, end the blocked backend, then readmit.
3. **Committed restart:** producer exits before any consumer starts. Observe Job
   and native queue row, start a fresh application/consumer and observe completion,
   one persisted Attempt and a separate effect row.
4. **Receive/claim death:** pause the real executor before its effect after a
   committed claim. Observe native delivery ID, lease and Attempt independently.
   Kill and reap; expire actual visibility and claim deadlines; a fresh native
   consumer completes with a new Attempt and interrupted old Attempt.
5. **Completion before ack death:** a test-only Ecotone after-interceptor pauses
   after Job completion and before native ack. Independently observe completion,
   effect and retained delivery. Kill, expire visibility and redeliver; counts stay
   one Attempt/one effect. No uniqueness constraint may hide repeated effects.
6. **Duplicate/obsolete:** insert additional real invitations through the native
   publisher. A promoted Job ignores old generations and completed redelivery;
   only its current generation executes. No fabricated handler calls.
7. **Competing consumers/busy claim:** overlap a paused valid Attempt with a second
   consumer receiving a duplicate invitation and unrelated work. The second
   consumer handles unrelated work without exiting on busy; retains delayed recovery
   intent, adds no Attempt/failure, and cannot spin while the retry is delayed.
   After death/expiry recovery completes; obsolete invitations are safe no-ops.
8. **Late priority promotion:** preserve OPS-C01's next-effect observation and
   add native invitation/generation inspection. Deliberately removing the upstream
   priority patch must fail the next-Job assertion, not a timeout.
9. **Infrastructure failure/exhaustion:** reject a real claim write independently
   of the executor. Native delayed retries exhaust into an inspectable dead letter;
   Job stays queued with zero domain failures/Attempts. Restore the write path,
   replay through the native gateway and observe completion of that same Job.
   Separately, executor exceptions produce failed Attempts and a terminal failed
   Job after its domain budget, not a transport dead letter.
10. **Real bootstrap integration:** use normal Tempest application boot, resolve
    Jobs first and Database first in separate processes. A Tempest mutation plus
    native enqueue share backend PID/transaction identity, visibility and rollback.
    Reconnect outside a transaction preserves sharing; reconnect within one is
    rejected. Without upstream connection injection, the proof must fail precisely.

## Fault boundaries and safety

Only disposable `stashd_tx_test_*` databases and generated restricted roles are
allowed, using the existing independent fixture allocator. Credentials travel via
stdin/private environment. Parent owns every bounded child, always kills/reaps it
on teardown and disposes database/role/files. SQL observers and effects use separate
connections. Native visibility/lease deadlines may be advanced explicitly for
bounded tests; this is eligibility manipulation, not an invented acknowledgement.

The effect-to-completion crash window is deliberately excluded from an exactly-once
claim: an arbitrary external effect may repeat if the process dies before saving
completion. No live stale-worker fencing, claim renewal or preservation crash safety
is implied. Vault/OCFL is outside these SQL transactions.

## Correspondence with baseline OPS-C02

| Baseline scenario | Native equivalent |
| --- | --- |
| Job/outbox rejection | Job/native-channel rejection and state-write rollback |
| Admission process death | Same precommit durability boundary |
| Restart before relay | Restart before direct consumption |
| Forward-target failure | Native claim/transport infrastructure failure and dead-letter replay |
| Relay forwarded before ack | Relay disappears; test consumer receipt/claim death instead |
| Worker completed before ack | Same native completion-before-ack death/redelivery |
| Reverse physical forwarding | No forwarding hop; duplicate/obsolete generations and explicit queue preference |

Forwarding order is not a product guarantee for independent Work. Completion order
is not guaranteed under concurrent consumers. Interactive preference applies to
eligible queue selection, not preemption, global completion order or fairness.

Negative controls must include split admission transactions, dropped committed
intent, completed-Job reclaim, missing independent effect, missing generation guard,
ignored native priority, busy invitation loss and bypassed injected Connection.
Retain failed controls and unproven cases in the evaluation report.

The receipt boundary is established by independently observing an assigned native
`delivery_id`, a committed running Job/Attempt and a live bounded executor pause.
For completion-before-ack an around-interceptor pauses after the real handler
returns; an `After` interceptor on the void handler did not reliably establish
this boundary in the evaluated version and was not accepted as evidence.

The precommit crash case terminates both the application child and its blocked
PostgreSQL backend before releasing the deferred-trigger lock. A COMMIT already
received by PostgreSQL can succeed after client death; a lost response is an
ambiguous commit, not permission to promise rollback. The test covers server-side
transaction abort while paused before commit; canonical readmission handles either
outcome without inventing a second active Work identity.

`tools/evaluations/native-negative.py` runs the ten-case native baseline, eight
specific sabotages (including the original protected priority assertion), and the
restored baseline. Its classifier requires the selected assertion location and a
single assertion failure; infrastructure exits, hook errors and timeouts are
verification errors. Source bytes are restored in `finally`; unexpected concurrent
edits retain an original-byte backup instead of being overwritten. Do not run it
concurrently with other tests or edits in this evaluation tree. SIGINT/SIGTERM
finish bounded child cleanup; SIGKILL/machine failure can require manual source
restoration from private original-byte backups. The retained JSON contains no
credentials, process output or private fixture descriptors.

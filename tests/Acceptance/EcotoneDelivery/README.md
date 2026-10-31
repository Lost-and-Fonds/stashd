# OPS-C02-E: native PostgreSQL durability

These executable contracts are the transport-native evidence for OPS-C02. They
run the actual application and official ecotone/tempest integration, not a separate
EcotoneLite system or simulated transport. OPS-C01 and installation contracts remain
independent tests. The complete correspondence with retired Messenger observations
is in docs/development/durable-delivery.md.

NativeDurabilityTest independently verifies:

1. Committed Job/invitation survives a fresh application and consumer.
2. Receipt/claim death recovers the same Job with interrupted/completed Attempts.
3. Completion-before-ack death preserves a real native row; redelivery adds no
   Attempt or independent effect.
4. Duplicates and obsolete generations cannot start incorrect execution.
5. Busy duplicates retain delayed recovery while another consumer handles unrelated
   Work; no domain budget or hot-loop execution is consumed.
6. Normal Tempest resolution in either order shares physical transactions; rollback,
   reconnect rejection and a non-default schema remain correct.
7. Queue insertion rejection rolls back admission and permits real readmission.
8. Native retries exhaust into inspectable dead letters without domain failure;
   native CLI listing/replay works and rejected replay preserves the original.
9. Domain failure has separate persisted Attempt outcomes and a truthful terminal Job.
10. A deferred real COMMIT barrier plus client/backend death leaves no partial intent.

TransportFailureTest additionally rejects only INSERTs after the second consumer
receives a busy invitation. Both busy rescheduling and native retry publication
fail, yet actual retained rows remain recoverable and the same Job completes after
repair. A CHECK constraint would also reject receipt UPDATEs and would not establish
that failure window; the insert-only trigger is deliberate.

Every fixture owns a generated database, restricted role and private workspace.
Actual Tempest migrations and explicit Ecotone provisioning run first. Credentials
are passed to fresh PHP processes on stdin. Independent PDO queries observe Job,
Attempt, queue, dead-letter and effect rows. Effects have no uniqueness constraint
masking repeated execution. Teardown stops/reaps children and independently verifies
database, role and directory removal.

Receipt/claim pauses happen in the real domain executor. Completion-before-ack
blocks the actual queue DELETE with a PostgreSQL advisory-lock trigger. The parent
observes the waiting backend and completed state, kills/reaps the process, aborts
the blocked backend and requires the retained row before recovery. Admission death
uses an actual deferred precommit trigger. A client death alone cannot revoke a
COMMIT already received by PostgreSQL.

Crash tests explicitly expire real persisted visibility and Job lease deadlines
after terminating the owner. The configured 60-second deadlines are independently
compared in PostgreSQL. These are local process/transaction proofs, not broker,
power/storage-loss or arbitrary network-partition simulations.

```sh
./bin/test.sh tests/Acceptance/EcotoneDelivery
python3 -B tools/verification/ops_c02_negative.py
python3 -B tools/evaluations/installation-negative.py
```

A passing baseline and restored suite are mandatory. Only a named first assertion
with the expected exception class counts as detected sabotage; setup/teardown
errors, malformed reports, wrong failures and timeouts are verification errors.
No arbitrary exactly-once external effect, live stale-worker fencing, lease renewal,
OPS-C03 completion or preservation crash-safety guarantee is claimed.

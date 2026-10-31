# OPS-C02: native durable Operations delivery

The product contract is durable accepted Work, atomic state and intent, recoverable
consumer crashes, truthful Job/Attempt outcomes and safe completed redelivery.
An outbox relay, Messenger envelopes and forwarding order were evidence mechanisms
of the retired implementation; they are not required product architecture.

The original seven Messenger-specific tests and their driver are replaced by:

- `../EcotoneDelivery/NativeDurabilityTest.php`: ten real PostgreSQL/native-consumer
  fault contracts, independent effects, real process death, retries and recovery.
- `DurableDeliveryTest.php`: actual priority-induced delivery reordering, exact
  effect/Attempt order and both authoritative completed Jobs.
- `../EcotoneInstallation/`: fresh schema, idempotent provisioning, nested commit,
  rollback/savepoint recovery, initialization order, reconnects and production cache.
- Integration Jobs, WorkAdmissionAtomicity and WorkExecutionContext tests: domain
  failure budgets, persistence failures, generation and executor metadata.

The complete old-test-to-new-proof correspondence is in
[durable-delivery.md](../../../docs/development/durable-delivery.md). No reliability
obligation was retired merely because the relay was removed.

Every native fixture is a disposable generated database and restricted role.
Workers run through normal Tempest boot and the official ConfiguredMessagingSystem.
Assertions query a separate connection and independent effects; the driver never
simulates a Job store, transport, acknowledgement or completion history. An
unavailable fixture is a failed test, not an incomplete or successful negative control.

Run through the canonical wrapper:

```sh
./bin/test.sh tests/Acceptance/Operations tests/Acceptance/OperationsDelivery tests/Acceptance/EcotoneDelivery tests/Acceptance/EcotoneInstallation
python3 -B tools/verification/ops_c02_negative.py
python3 -B tools/evaluations/installation-negative.py
```

Receipt/acknowledgement crashes, retries, visibility and lease manipulation are
bounded local PostgreSQL proofs. Live stale-worker fencing, lease renewal, remote
brokers and exactly-once arbitrary external effects remain outside OPS-C02.

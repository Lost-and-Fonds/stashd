# Work admission: bounded Operations slice

`Jobs::request(Work $work, string $priority = 'routine'): string` returns the durable
Job ID for a canonical `(type, scope, mode)` identity. Identifiers are stable,
case-sensitive ASCII strings of at most 200 bytes. Observation is a nonempty input
snapshot of at most 1024 characters, not part of identity. A repeat while active
joins the existing Job and retains its original observation. Callers must provide
canonical identifiers; this API does not normalize aliases.

Departments own durable desired state and define when coverage is satisfied.
Operations owns durable Job execution, coalescing and scheduling. A returned Job
ID acknowledges admission or joining, not coverage of the supplied observation.
Operations does not retain newer observations or automatically schedule follow-up
Work. Departmental reconciliation after Job termination and process restart is
mandatory: durably retain unmet demand, compare achieved coverage with desired
state, and request another Job only when the required outcome remains unmet.
The first real departmental integration must prove this through acceptance tests,
including a source change during execution and restart with outstanding demand.

For Refresh, reconcile the latest required source state; intermediate observations
may be superseded. For Complete, reconcile the department's defined coverage
target; a changed observation requires follow-up only when that target remains
unsatisfied. If every source revision must be preserved, the department must
retain those targets explicitly rather than treating observations as a lossless
queue. Observation strings have no ordering or coverage semantics in Operations.
Use namespaced Work types and scopes that include the owning source/tenant where
needed. Departments validate their supported types and modes before admission;
Operations validates syntax and rejects unknown priorities. Executors must also
reject unsupported Work rather than reporting success.

Only queued and running Jobs reserve Work. Completion or terminal failure releases
that reservation, allowing a new Job without deleting history. The old
`Jobs::admit(key, payload)` example retains its permanent-key semantics for existing
callers. New code should use `request` for logical Work.

Interactive requests promote routine Jobs without changing Job identity. Queued
promotion increments delivery generation and stores a replacement native invitation
in the same transaction. Obsolete generations cannot claim an Attempt. Running
promotion records desired priority without interrupting the existing Attempt.
The native PostgreSQL channel orders interactive invitations ahead of routine
invitations at dequeue. This is queued preference, not preemption or a fairness
guarantee under sustained load.

Bind a domain `WorkExecutor` through Tempest's container. Its public method is now
`execute(Work $work, ExecutionContext $context): void`, where
`App\Operations\DTO\ExecutionContext` is immutable and contains `jobId`,
`attemptId`, `generation` and `leaseExpiresAt` (`DateTimeImmutable`). It must reject
unsupported Work and throw on unsuccessful execution.
A missing executor fails Work rather than inventing completion. The test-only
probe implements this port and writes one durable effect per call through a
separate connection, with no uniqueness constraint that could hide duplicates.
Real StashInput-to-Work mapping remains separate work.

Context describes the persisted claim: the Attempt ID is currently also its claim
token, and the lease deadline comes from the claim's database write. Each retry or
recovery receives a distinct Attempt ID, even when the Job stays the same.
Generation identifies a delivery revision, not a monotonic fencing token; recovery
can create a new Attempt in the same generation. Context is descriptive metadata,
not proof of fencing, lease renewal, cancellation or permission to perform external
effects. Do not use Attempt ID as an external-effect deduplication key across
retries: departments must choose stable effect identities for their outcomes.
Future events must use the EventManagement catalogue, including its distinct Job
and Attempt identities; this change adds no emitters or parallel event vocabulary.

Run `integration:init` and native Ecotone database setup before starting consumers.
This is a pre-release clean-install design; there are no historical messages or
production databases to upgrade. Real Attempt starts and terminal outcomes remain
persisted rather than reconstructed from queue state.

## One transaction boundary

The official ecotone/tempest integration uses the pinned Tempest connection-injection
fix and the proposed library-owned DbalBackedConnection. Ordinary application
persistence remains Tempest Database. Its public Connection methods and native
Ecotone delivery use the same DBAL owner, including nested savepoints. Inner
admission never commits an outer application transaction. Failed inner admission
rolls back both Job and invitation while unrelated outer work may still commit.

The PostgreSQL advisory transaction lock, active-only uniqueness index and conditional
claim/finalization statements remain domain-specific SQL. Tempest query/schema
facilities were inspected; they do not express all these PostgreSQL concurrency
operations. There is no Doctrine ORM or second application repository framework.
Tempest owns ordinary queries, schema migration and validation.

Queued promotion advances generation and inserts a higher-priority native invitation
atomically. Old invitations remain safe because only the current generation can
claim; native priority propagation is protected by its failing negative control.
Claim/Attempt writes commit before domain execution. Completion or a failed-domain
retry commits its state/history changes together with any replacement invitation.
The consumer is not wrapped in one long transaction covering external effects.

## Evidence and limits

OPS-C01's independent assertions remain unchanged and run through real application
bootstrap, actual native consumers and fresh processes. The concurrency driver
waits for every requesting child to be blocked in an actual PostgreSQL admission
transaction before releasing the barrier. Effects are independently inserted with
no uniqueness constraint hiding duplicates.

Installation contracts additionally verify initialization order, physical backend
and transaction identity, nested rollback/savepoints, private-schema reconnect,
production cache lifetimes and clean provisioning. See durable-delivery.md for the
complete correspondence with retired Messenger observations and adversarial tests.

Department-owned reconciliation is still mandatory. No broader workflow/saga,
remote broker, cancellation, lease renewal or OPS-C03 fencing implementation is
added. An external effect may precede failed completion persistence and can repeat
on recovery; the acceptance test records that window honestly. Vault/OCFL authority
and EventManagement catalogue classifications remain separate from message delivery.

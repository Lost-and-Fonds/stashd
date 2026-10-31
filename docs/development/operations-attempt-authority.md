# Execution authority (OPS-C03)

Ecotone delivers Work. Operations grants execution authority. Preservation decides
whether an archival result is valid. A delivery generation is a routing revision;
it is never an execution fence. The persisted Attempt UUID is the claim identity.

`AttemptAuthority::protect(ExecutionContext, callable)` is the host boundary. The
callback receives the shared Doctrine connection and must perform only short
transactional database operations through that connection. It must not commit,
change claim identity, call remote systems, or hold a transaction during a download.
The Job row is locked before checking the persisted current Attempt and PostgreSQL
`clock_timestamp()`. The same check after the callback rolls back the entire unit
if its work ran past expiry. The final check is the protected operation's
linearization point. Once committed, a delayed response does not revoke that write.
The callback must not expose uncommitted results to another system.

`AttemptAuthority::finish()` protects terminal transitions and their retry publication,
checking the locked lease again before allowing the transaction to commit.

`AttemptAuthority::renew()` extends only the current unexpired Attempt. It preserves
Work, generation, progress and history. `renewal()` returns a typed `LeaseRenewal`
with the actual conditional statement's decision time and accepted deadline. These
receipts are diagnostic evidence, never permission for a later operation. Executors renew between bounded units;
`LeaseHeartbeat::run()` uses Tempest's process executor to supervise an external
tool, renewing at one third of the configured interval. It stops the child when
renewal fails. Host monotonic time schedules polling only; PostgreSQL decides
ownership. Set `STASHD_EXECUTION_LEASE_MS` (default 60000). Transport visibility
remains independently configured in Ecotone. Busy deliveries retain delayed native
intent and do not consume the domain failure budget.

Claim, renewal and terminal writes all serialize on the Job row. Takeover retains
the former Attempt's UUID/start time and records it as interrupted, then creates a
distinct Attempt in the same delivery generation. A stale completion or exception
is an expected fenced outcome and settles its native delivery without mutating a
successor or publishing a domain retry. Expiry itself revokes authority; takeover
is not required. An expired worker settling the final invitation while still the
current claimant must retain an invitation for recovery.

`ExecutionArtifacts` supplies the bounded host integration: checkpoints, immutable
custody receipts with retained file location and SHA-256, per-Attempt reconciliation,
and result adoption. A successor must actually rehash retained files before it can
adopt a result. Hashing happens outside the Job lock; the short guarded write
records exactly the receipts that were verified. A new receipt requires another
reconciliation before adoption. The Job lock prevents duplicate result rows; there is no uniqueness
constraint hiding duplicate writes. The host must retain immutable files across
process death. This is a bounded handoff, not the Vault or an OCFL journal. Do not
supply untrusted arbitrary filesystem paths to this internal host service. Retained
file lifecycle and authoritative archival validation remain Preservation's duties.

The authority API requires no knowledge of Ecotone delivery. Departments receive
an ExecutionContext for correlation, then ask Operations to protect each database
mutation. A context, PID, initial deadline or successful earlier check grants no
continuing authority. External operations still require stable idempotency keys and
independent reconciliation. Neither a lease nor killing a child promises exactly
once effects in a remote system.

## Framework choice

The installed Tempest configuration discovery, migration `CreateTableStatement`,
shared connection integration, `Database::withinTransaction`, query statements and
process interfaces were inspected. Configuration, schema evolution and process
supervision use those facilities. Operations keeps its existing shared DBAL
transaction owner. PostgreSQL `FOR UPDATE`, conditional writes, `clock_timestamp()`
and atomic claim/history transactions are isolated in Operations because ordinary
CRUD or a transaction-start timestamp cannot express the required expiry and lock
ordering semantics. These exceptions must be revisited if Tempest gains an
equivalent atomic database-clock authority facility; framework replacement must
preserve the interleaving tests.

## Verification

The approved nine assertions are unchanged. The installed production-backed driver
creates a disposable restricted PostgreSQL database/role, uses normal Tempest
bootstrap and actual native Ecotone consumers, and pauses real living executors.
Commands invoke the production authority and artifact services inside those
executors. Observers use separate PostgreSQL sessions. Queue INSERT triggers retain
committed publication history even after acknowledgement deletes a message.
Database lock barriers exercise both renewal/takeover orders. The driver may change
transport visibility but never fabricates a Job deadline or ownership predicate.
Teardown stops supervised processes before dropping the private database and role.

Additional tests exercise expiry during a guarded transaction, periodic renewal
while a real child tool runs beyond its original lease, unchanged history and
publication after rejected renewal, and refusal to adopt tampered custody. Negative-control evidence
and final QA results belong in `verification/ops-c03/` alongside the review report.

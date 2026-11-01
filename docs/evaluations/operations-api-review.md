# Operations API and model review — 2026-11-01

This supersedes the API/model portions of [the implementation checkpoint](operations-implementation.md). Execution remains Tempest-hosted Messenger + Durable; this pass adds no scheduler, transport, workflow interpreter or compatibility API.

## Identity and public API

`App\Identity\Identifier` and backed `IdentifierType` share the existing archival convention: `urn:stashd:<type>:<uuid>`. The archival enum moved into this shared namespace. Vault's existing helper now delegates formatting to it; supplied archival UUIDs, UUIDv4 generation, deterministic archival identities, external Item URIs and plugin URIs retain their semantics. The repository's Vault implementation/tests establish the convention; the consulted Working Specification and PREMIS notes supplied no conflicting Operations format.

Assignment, Attempt and Demand have distinct enum cases. `Identifier::parse($urn, $expectedType)` rejects bare UUIDs, unknown namespaces/types and malformed UUIDs. `uuidFor($expectedType)` rejects cross-type substitution. Tempest casting/serialization and JSON use the URN. AssignmentContext validates all three types; its IDs describe correlation, never authority.

Each entity has one UUID primary key named `uuid`. Its virtual `id` computes the typed URN from that same UUID. There is no additional identity record or persisted URN. Assignment UUID is also Durable's execution UUID. The internal dispatcher, workflow payload and foreign keys use UUIDs deliberately; handlers and the public Worker boundary use typed identifiers. Assignment JSON exposes its URN, state, priority and error, excluding storage fields.

```php
// Executable example: tests/Support/Durable/DepartmentJob.php and DepartmentHandler.php.
$assignment = $worker->do(new DepartmentJob('asset-42'), Priority::Interactive);
$id = (string) $assignment->id; // urn:stashd:assignment:...
$latest = $worker->status(Identifier::parse($id, IdentifierType::Assignment));
if ($latest->state === AssignmentState::Failed) {
    report($latest->error); // department-specific reporting
}
```

A Job implements only `key(): string` and `observation(): string`. Required serializable input belongs in its constructor. `#[HandlesJob(DepartmentJob::class, 'department.probe.v1')]` registers its stable persisted type; the database never stores its PHP class name. After a class rename, registration can bind the same alias to the replacement class, provided the serialized input contract remains compatible. Message/schema evolution itself remains unfinished.

Admission identity is `(registered type, Job key)`. Jobs include meaningful modes in their keys. Observation is separate input identity. Admission retains distinct observations, rejects reuse of an observation for different serialized input, coalesces equivalent concurrent requests, promotes actual queue delivery and allows fresh admission after terminal completion. Rejection rolls back promotion/publication too. This deliberately does not use Tempest's cache-backed request idempotency middleware: cached responses and TTLs do not implement active-work coalescing, retained demand or terminal re-admission. Tempest validation, mapping, discovery, DI, queries and migrations remain in use.

`Worker` owns admission, read-only `status()` and the typed operator recovery entry point. `AssignmentExecution` owns internal workflow start, handler invocation, acknowledgment, recovery delivery and terminal failure reconciliation. Admission reconciles failure under the identity lock; status never writes. The returned object is the actual Assignment entity, not a second snapshot class. Its virtual `state` is an observation, refreshed by `status()`. Current unsatisfied demand, its current Attempt and live session determine Running; a historical ActivityTaskStarted cannot make the next queued demand Running.

## Domain entities

| Entity | Durable fact and identity |
| --- | --- |
| Assignment | Stable type/key, priority, active admission and terminal error; typed public URN |
| Demand | Immutable observation/payload, satisfaction and next-demand receipt, current Attempt; typed public URN |
| Attempt | Physical handler episode, owner session and outcome/times; typed public URN |
| Checkpoint | Protected execution evidence, linked to Assignment/Demand/Attempt |
| CustodyReceipt | Acknowledged immutable path/digest under an Assignment-scoped custody key |
| Result | Adopted coverage for one Demand, linked to its authoritative Attempt |
| Reconciliation | Which Attempt independently verified which retained CustodyReceipt |

New entities use required constructors; Tempest hydrates persisted fields directly. Virtual identities and base state therefore derive from hydrated fields. Checkpoint, CustodyReceipt, Result and Reconciliation have internal UUID surrogate keys; no public URNs are needed for these records yet. Custody keys, not surrogate IDs, are the caller's acknowledgment identity. AssignmentContext and ActivityOwnership remain readonly ephemeral values.

The former generic artifact table and ArtifactKind enum are deleted. Custody receipts have unique `(assignment_id, key)`, Results unique `demand_id`, and Reconciliations unique `(attempt_id, receipt_id)`. Retained receipt bytes cannot be silently replaced. A successor verifies them before adopting a Result; old receipts remain linked to their original Attempt. Conflicting Result adoption is rejected. All mutations still pass through PublicationAuthority's pre/post ownership checks inside the short protected transaction, including the current-Attempt row lock. Files are caller-retained immutable staging, not atomic filesystem publication.

Production Operations PHP falls from 44 files / 2,615 lines at `596fabb` to 37 files / 2,188 lines (including comments); shared typed identity is three files / 116 lines. Most removed engine code is preserved as a test baseline, not claimed as deletion from the repository.

The unreleased domain migration now defines this fresh schema through Tempest. No existing database was migrated, truncated or dropped except generated disposable test fixtures. An installation of an earlier PR83 schema needs an explicit development reset or a deliberately written data migration; the existing migration name does not upgrade it automatically.

## Deletions and upstream ownership

Removed from production: Work, Jobs, JobRuntime, WorkExecutor, ExecuteJob, JobCommands and the old ExecutionContext. Their implementation is retained under `tests/Support/HistoricalOperations` exclusively for historical Messenger baseline tests; legacy CLI discovery is confined to that fixture's console. These baseline tests are still not proof of new-department C02 completeness.

Also removed: AssignmentRecord, AttemptRecord, the readonly Assignment snapshot, ExecutionArtifact, ArtifactKind, `Worker::find()`, `Assignments::save()`, the old work_type/scope/mode fields and persisted job_class.

Upstream still supplies DBAL journal/history/fencing, workflow replay, retry decisions, session-lock exclusion, Messenger workers, retry/failure transports, native outbox and the actual Durable Bundle Messenger adapters. Stashd owns typed admission/demand, priority routing, publication authority and bounded custody evidence. No new independent execution state machine was introduced. The two historical Jobs migrations remain for the baseline fixtures (including Messenger table setup); production does not run that engine. Decoupling and removing that unused baseline schema can follow the C02 driver port.

## Submitted review coverage

GitHub returned **12** inline comments (the request described 11). All remain unresolved for human verification.

| Inline comment | Change |
| --- | --- |
| [4434138370](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434138370) | Job owns key/observation; production Work removed |
| [4434148007](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434148007) | Removed repetitive narrative comments; retained concurrency/recovery invariants |
| [4434151815](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434151815) | Assignment constructor |
| [4434153910](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434153910) | Assignments::update() |
| [4434154750](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434154750) | Imports across touched integration classes |
| [4434155925](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434155925) | One real Assignment entity |
| [4434157522](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434157522) | Demand constructor; other entities constructor-built too |
| [4434159288](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434159288) | Read-only Worker::status() |
| [4434161138](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434161138) | AssignmentRecord replaced by Assignment |
| [4434161369](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434161369) | AttemptRecord renamed Attempt |
| [4434162479](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434162479) | Stable job_type + opaque work_key replace Work fields |
| [4434164743](https://github.com/Lost-and-Fonds/stashd/pull/83#discussion_r4434164743) | Separate Checkpoint, CustodyReceipt and Result |

No submitted comment requires an unresolved design decision. Human review should verify the choices above; no threads were resolved automatically.

## Verification

The focused PostgreSQL/process tests cover coalescing, distinct modes, new demand during execution, native-outbox rollback, queued and post-scheduling promotion, fresh-process replay, safe redelivery, explicit scheduling recovery, killed-worker custody recovery, stale publication/failure rejection and the four-episode Durable retry budget. New tests add typed URN/type rejection, Tempest serialization, direct ORM retrieval, conflicting observations, stable registration aliases and current-state projection. A direct ORM test caught constructor-skipping hydration; state now derives from persisted fields rather than constructor initialization.

The four accepted admission/priority contracts retain their assertions. Only fixture/driver vocabulary and historical imports/CLI discovery changed. Six negative controls independently removed outbox publication, propagated scheduling failure, omitted the final mutation check, bypassed the journal fence, ignored conflicting input and inferred Running from history. Every violation failed its intended assertion; baseline and restored control sets both passed (six cases / 38 assertions).

| Contract | Evidence after refactor | Status |
| --- | --- | --- |
| OPS-C01 | Unchanged concurrent admission, distinct modes and terminal re-admission assertions on new production driver | Passing |
| OPS-C06 queued promotion | Unchanged execution-order assertion plus post-scheduling promotion | Passing bounded portion; fairness untested |
| OPS-C02 | Direct new-path crash/redelivery/outbox/recovery tests; historical baseline still separately exercised | Partial; accepted delivery driver not fully ported |
| OPS-C03 | Direct live-session loss, in-mutation rollback, stale failure and custody/reconciliation proofs | Partial; full accepted suite and session-vs-expiry specification review outstanding |
| OPS-C04/C07 | Attempt history and Durable retry exhaustion preserved | Partial |
| OPS-C05/C08–C10 | Pause/cancel, capacity, complete observability and evolution | Incomplete/untested; unchanged scope |

Focused: **41 successful cases**, 226 assertions (22 passed, 19 deprecated), no failures. Full: **467 passed, 35 deprecated, 33 incomplete**, 24,032 assertions, no failures, 159.65 seconds. Deprecations report `ReflectionProperty::setAccessible()` under PHP 8.5. Formatting passes; lint and analysis report no errors or warnings (two and four informational help notices respectively). Commands and negative-control failures are recorded in [verification evidence](operations-api-review-evidence.txt).

C02/C03 completeness remains explicitly unclaimed. The existing delivery/fencing driver work, specification review for session ownership versus TTL expiry, full Vault publication and deployment/schema evolution remain outstanding. This refactor neither relaxes those contracts nor treats the old unsafe Durable characterisations as passed safety guarantees.

## Structural cleanup — 2026-11-01

Removed production `ExecutionArtifacts` and `Persistence/Assignments`. Ordinary Assignment/Demand reads and writes now use Tempest queries at their transaction owner. `AdmissionLock` retains only the shared `(job_type, work_key)` PostgreSQL transaction lock and its application connection: admission and final demand satisfaction must serialize on exactly the same key. The partial unique index, immutable observation conflict check and native outbox transaction remain unchanged.

Handler API (the runnable DepartmentHandler exercises these operations):

```php
Checkpoint::record($authority, $context, 'staged');
$custody->acknowledge($context, 'acquired-bytes', $immutableStagingPath);
$custody->reconcile($context);
Result::adopt($authority, $context, 'verified');
```

Checkpoint and Result own their protected database operations; authority is explicit, with no service lookup or stored connection on the models. `CustodyHandoff` is retained because acknowledging and independently verifying immutable staged files crosses filesystem, receipt and reconciliation boundaries. It owns no OCFL publication. Digests are computed outside short protected transactions; callers must keep staging immutable. Verification does not authorize a later filesystem rename or guarantee bytes cannot subsequently change.

Worker still owns admission and read-only observation; AssignmentExecution coordinates upstream workflow start/recovery and handler acknowledgment. PublicationAuthority remains the protected transaction boundary. JobRegistry retains discovery validation and stable persisted aliases. ActivityDelivery retains real Messenger priority routing and recoverable enqueue failure semantics. The connection adapters and Durable initializer remain necessary framework boundaries; none is replaced with a new repository or execution engine. Further cleanup candidates are incidental runtime wiring and repeated query shapes, not justification for restoring a generic CRUD facade.

Running now requires an unsatisfied Demand's current Attempt token, Running outcome, no end time, and ownership of the **exact** advisory lock in the correct database by the recorded PID/backend-start session. Attempt stores `lock_key`; SessionActivityClaims shares its read-only `owns()` predicate with PublicationAuthority. A live backend without that lock is Queued. Status does not update Attempts or admission state. Session death also fails the same predicate. This is a snapshot, never permission for a subsequent mutation.

The new independent-process regression releases advisory locks **without killing PHP or its PostgreSQL session**, queries status before takeover, checks read-only persistence and exercises rejection through Checkpoint, custody acknowledgement, reconciliation and Result adoption. A fresh successor then executes while the stale process remains alive. Existing killed-worker custody, changed-byte rejection, idempotent acknowledgement, conflicting Result rejection, retry, outbox and accepted C01/C06 assertions remain exercised.

The unreleased fresh-schema definition adds Attempt.lock_key. Existing development schemas require an explicit reset or migration; no non-test database was modified. A trial delivering only after stale-handler rejection reached the failure-transport recovery boundary rather than ordinary completion. That broader failed-transport replay scenario remains outstanding C02 work; the new regression proves takeover before the stale handler exits, not automatic recovery from every rejected delivery. C02/C03 completeness remains unclaimed.

Verification for this section: focused 26 successful cases (6 passed, 20 deprecated), 205 assertions; full Lerd `composer qa` passed formatting, lint, analysis and all executable tests: **467 passed, 36 deprecated, 33 incomplete**, zero failures, 24,052 assertions, 126.39 seconds. Ten negative controls failed their intended assertions; the restored combined suite passed. Deprecations still report ReflectionProperty::setAccessible() under PHP 8.5. No review threads were resolved.

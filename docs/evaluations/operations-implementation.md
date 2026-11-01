# Operations implementation — 2026-11-01

## Architectural map

- Injectable `Worker::do(Job, Priority)` returns an Assignment snapshot promptly.
  Concrete typed Jobs identify Work and serialize input; discovered handlers receive
  constructor injection. Departments do not reference queues or workflows.
- Assignment UUID maps to one Durable workflow UUID. Assignment models own canonical
  Work admission and retained observations; Durable remains execution-history truth.
  Each observation becomes a separate Durable Activity within that Assignment;
  a request after all retained observations settle creates a new Assignment.
- Tempest models represent assignments and pending observations. Durable/Messenger
  tables remain upstream persistence. SQL is reserved for admission serialization,
  partial uniqueness and conditional authority transitions.
- Admission and Messenger outbox publication share the application transaction;
  the journal uses a separate DBAL connection. No business transaction spans work.
- Interactive promotion routes an additional current invitation to the interactive
  queue; a generation/current-status guard makes obsolete invitations harmless.
- Activity exclusion uses PostgreSQL session ownership. Context carries identity,
  never authority. Protected mutations must validate current authority at their
  atomic database boundary. Canonical filesystem publication requires its separate
  staging/verification protocol; an SQL transaction cannot authorize a later rename.

This is a working map. Verification and delivered scope will be recorded below.

## Delivered vertical slice

Normal Tempest boots discover `#[HandlesJob]` handlers without a database connection.
`Worker` is an injectable service, so the idiomatic call is `$worker->do(...)` rather
than a static global facade. Constructor injection applies to handlers. Public APIs
use backed Priority and AssignmentState enums; persisted Attempt outcomes and artifact
kinds also use enums.

```php
// Inject App\Operations\Worker into the submitting department.
$assignment = $worker->do(new PreserveAsset($assetId), Priority::Interactive);
$latest = $worker->find($assignment->id);
if ($latest->state === AssignmentState::Failed) {
    // Report $latest->error and retain the failed request for an explicit decision.
}
```

`PreserveAsset` illustrates a department-authored Job; Preservation integration is
not implemented in this change. A real executable authoring example is
`tests/Support/Durable/DepartmentJob.php` and its discovered `DepartmentHandler`.
A Job implements `work(): Work` and has public properties Tempest can map to JSON.
A handler is an invokable service carrying `#[HandlesJob(TheJob::class)]`; its
parameters are a concrete typed Job and AssignmentContext; parameter names are unrestricted. The context supplies Assignment, demand
and physical Attempt IDs. It grants no detached authority.

The production path is generic: discovered typed Job -> Tempest model admission ->
Messenger native outbox -> StartAssignment -> upstream Durable workflow -> upstream
Activity handler -> injected departmental handler -> protected acknowledgment ->
Durable outcome/resume. No test-only workflow registration participates in that path.

An Assignment owns all distinct observations that arrive while it is active. Each
observation has immutable input, a stable demand ID, a satisfaction acknowledgment
and the next-demand receipt. Durable schedules a distinct Activity per observation.
After acknowledgment, redelivery returns that receipt rather than executing the
handler again. New demand arriving during an Activity is retained and executed next;
a request arriving after the last acknowledgment creates a new Assignment. Domain
Completed means retained demand is acknowledged; final workflow bookkeeping may lag.

## Persistence and transaction ownership

Tempest models: AssignmentRecord, Demand, AttemptRecord, ExecutionArtifact and
Reconciliation. Assignment is a readonly public snapshot, and AssignmentContext and
ActivityOwnership are ephemeral values. There are no models shadowing Messenger's
transport, Durable's event journal or internal lock records.

Tracked Tempest migration creates domain tables with UUID foreign keys, enum checks,
unique observations, and a partial unique index for active Work. `operations:install`
uses the existing tracked migration entry point, then upstream schema setup. Ordinary
CRUD and mapping use Tempest. PostgreSQL-specific SQL is confined to canonical Work
serialization, authority row locking, and independent exact-session ownership checks.
The cyclic current-Attempt foreign key and partial index are explicit schema SQL.

The exact upstream Tempest connection-injection fix `5e56e440...` is pinned in Composer.
`ApplicationConnection` adapts Tempest's small Connection interface to the same DBAL
session used by admission/outbox. DBAL owns transaction nesting. This is independent
of Ecotone. Durable's journal and claim sessions remain separate; neither a workflow
pass nor a blocking handler is wrapped in a business transaction.

## Execution authority and bounded custody

SessionActivityClaims delegates exclusion to upstream LockActivityAttemptClaim and
Symfony PostgreSqlStore. It retains the granted session PID, backend start time and
exact advisory lock key. PublicationAuthority checks these through another session.
A domain Attempt token serializes takeover with protected mutations on the demand
row. Both pre- and post-mutation checks run inside the short transaction. A successor
cannot replace that token while the mutation holds the row lock. The final live-lock
observation is the linearization point; a later response cannot invalidate that
already committed mutation.

AuthorityJournal is a small decorator around the upstream fenced DBAL store. It
forwards workflow pass epochs unchanged and adds the missing Activity-side authority
hook. Activity journal writes use their own short journal transaction, without joining
application transactions. This rejects stale completions and stale handler failures
before they consume Durable's retry budget or publish its next-attempt message.
Before domain execution begins, Activity journal writes still require the exact live
session claim. No custom journal backend, interpreter or lease-renewal loop exists.

ExecutionArtifacts provides protected checkpoint, custody, reconciliation and result
operations. Custody hashes caller-retained immutable files outside the transaction;
a recovering Attempt must independently verify every retained receipt before result
adoption. An existing custody key cannot be rewritten to acknowledge changed bytes.
A result is idempotent for its demand and conflicting adoption is rejected.

These are **database handoff guarantees**, not atomic filesystem publication. Callers
must stage and retain immutable files; Operations does not rename files into the Vault,
make remote effects exactly-once, or implement the complete Preservation/OCFL protocol.

## Scheduling failure and recovery

ActivityDelivery reuses Bundle MessengerActivityTransport while routing according to
admitted priority. For the initial scheduling send only, a transport exception is
logged and the committed ActivityScheduled event remains unresolved and resumable.
It is not converted into a domain algorithm failure. Retry-attempt enqueue failures
continue to propagate to upstream recovery; they are not swallowed.

`operations:recover <assignment>` reissues only journalled activities that have never
started and have no terminal outcome. It refuses terminal Assignments. Repeated
recovery is safe through upstream claims and settled guards. This covers deterministic
initial enqueue rejection and is also a candidate recovery for a crash between the
schedule and send; that exact crash window has not been separately injected here.
This is explicit operator recovery, not a periodic automatic reconciler. Inspect the
Assignment with `operations:inspect`; enqueue errors are in the normal Tempest log.

## Workers and retries

`operations:work` is the normal Tempest CLI entry point. `--once`, `--receiver` and
`--limit` support bounded operation. Native Messenger workers, retries, failure
transport and OutboxSender perform transport work. SIGTERM/SIGINT request a clean
worker stop and previous process signal handlers are restored afterward.

Interactive admission promotions preserve identity and affect actual ordering, both
before initialization and after an Activity has already been scheduled. Obsolete
invitations are handled by upstream metadata, claim and settled-outcome checks.
No fairness or starvation guarantee is claimed beyond queued promotion.

Durable owns a bounded three-retry handler budget (four physical episodes). Attempt
records describe those episodes; there is no second Stashd error-budget algorithm.
Exhaustion exposes Failed through Worker::find. Starting a legitimate subsequent Job
preserves the failed history. Same-Assignment administrative replay after exhaustion
is not yet implemented.

## Verification and contract status

The four accepted OPS-C01/queued-C06 contracts are unchanged. Their default driver
now targets this production department. Eight independent processes must actually
wait at the PostgreSQL admission boundary, and execution effects are independently
observed. The earlier historical driver remains available for baseline comparison;
OPS-C02's historical driver was not relabeled as Durable evidence.

| Contract | Current evidence | Status |
| --- | --- | --- |
| OPS-C01 admission/identity/re-admission | Four unchanged accepted tests, real PostgreSQL and independent processes | Passing on the new department |
| OPS-C06 queued promotion | Unchanged accepted ordering assertion, plus promotion after scheduling | Passing bounded portion; sustained fairness untested |
| Unmet observations | Distinct demand retained before and during active execution, immutable input and receipt replay | Direct production-path tests |
| OPS-C02 delivery | Native outbox rollback, fresh-process execution, redelivery, killed worker and explicit scheduling recovery | Direct bounded proofs; full accepted delivery driver not ported |
| OPS-C03 authority | Live stale worker rejected after takeover, revocation inside mutation rolls back, stale failure cannot consume Durable budget | Direct independent process proofs; not all nine accepted contracts |
| OPS-C03 custody | SIGKILL after acknowledged custody; successor verifies before adoption; changed bytes rejected | Direct bounded handoff proof |
| OPS-C03 expiry/renewal | Session authority has no expiring lease or renewal operation | Proposed mechanism change requires specification review; not marked passed |
| OPS-C04 history | Real Attempt episodes retained, interruption on takeover and bounded retry episodes | Partial |
| OPS-C05 pause/cancel | Not implemented | Incomplete |
| OPS-C07 retries | Durable's finite handler budget and terminal failure | Partial; cooldown and administrative replay incomplete |
| OPS-C08 resources | One ordinary worker process per configured consumer | Headroom/control-capacity contracts untested |
| OPS-C09 observability | Typed lifecycle snapshots, worker CLI and enqueue error logging | Partial; no health/event inventory |
| OPS-C10 evolution | Stable invitation class and versioned internal workflow name | Compatibility contracts untested |

Negative controls: removing the final ownership check caused a write to escape after
session revocation and failed the rollback assertion. Restoring propagation of initial
activity-send errors caused the scheduling-recovery test to fail with domain Failed.
Both deliberate breakages were restored before verification. Earlier spike execution
negative control remains documented separately.

## Inventory and comparison

Upstream supplies journalling, workflow replay/fencing, activity retries, settled guards,
resume protocol, transport adapters, native outbox forwarding, transport retry/failure
handling, workers and PostgreSQL session exclusion. Stashd owns typed admission,
observations/satisfaction, priority routing, Tempest configuration/discovery/connection
binding, bounded domain publication authority, and custody/result semantics.

The domain admission path no longer calls JobRuntime, Jobs or WorkExecutor. Their old
claim/lease/failure/generation machinery is not part of new execution. They remain
untouched because the historical delivery tests still exercise them. Deleting them
before the remaining delivery contracts have a new production-backed driver would
remove a useful baseline prematurely. No old migration or persisted history is erased.

This implementation is larger than the initial demo because it includes actual domain
admission, custody and authority. A feature-equivalent code reduction versus PR #81/#82
is not yet established. Full-stack replacement is not recommended yet.

## Remaining priorities

1. Port the remaining accepted OPS-C02/C03 driver actions, including lost acknowledgment,
   failed-transport replay, overlapping successor mutations and durable enqueue audit.
2. Review the explicit session-authority specification change. Lease expiry and renewal
   assertions cannot honestly be called passing under a non-expiring session mechanism.
3. Add same-Assignment recovery after domain retry exhaustion, with explicit custody and
   unmet-demand semantics. Failed observations remain persisted today.
4. Integrate a real Preservation or Discovery Job through the guarded domain boundaries.
   Arbitrary existing department side effects do not become protected by using Worker.
5. Add timer/child-workflow handling, cancellation, resource limits, fairness, health events
   and deployment/message-evolution contracts as separate verified slices.

Known limits: initial-activity recovery is explicit, not automatic; arbitrary remote or
filesystem effects remain the domain handler's responsibility. A locked PostgreSQL
session that is neither dead nor revoked can intentionally hold ownership indefinitely;
network failure detection depends on PostgreSQL/TCP policy. Native transport keepalive
for many-hour blocking handlers has not been integrated or load-tested.

## Operational setup

Supply the existing STASHD_OPERATIONS_DSN, STASHD_OPERATIONS_USERNAME,
STASHD_OPERATIONS_PASSWORD and STASHD_OPERATIONS_SCHEMA settings through the normal
private environment configuration. The DSN is PostgreSQL; the schema is a validated
private identifier. A database owner initializes the schema explicitly:

```sh
lerd console operations:install --no-interaction
lerd console operations:work --no-interaction
lerd console operations:inspect <assignment-id>
lerd console operations:recover <assignment-id>
```

No database is opened merely to discover handlers or load configuration. OperationsConfig
now implements Tempest DatabaseConfig directly, and the default application Connection
initializer establishes the DBAL binding before any model resolves. The redundant
OperationsConnectionConfig adapter was deleted. A regression test deliberately resolves
Tempest Database before Worker, writes business data and admits work inside one outer
transaction, then aborts it: business data, Assignment and outbox all disappear together.

The worker CLI is exercised as the actual `php tempest operations:work` command. Its
readiness proof observes repeated native transport polling from its PostgreSQL session;
SIGTERM then produces exit 0. The setup command is also exercised for tracked-migration
idempotence. Normal fixtures now use the real tracked Tempest initialization path rather
than hand-compiling the new schema.

## Final verification record

Verified implementation commit: `0b4616b` (following dependency milestone `bd7be5c`).

- Final full canonical test run: **451 passed, 33 incomplete, 30 deprecated, zero
  failures; 23,972 assertions; 158.38 seconds**, four parallel processes.
- Focused run: the 16 new department tests, four unchanged accepted admission/priority
  tests and seven historical delivery-baseline tests completed with **27 successful
  tests, 329 assertions, zero failures** (14 were labeled deprecated by Pest).
- The historical delivery successes remain baseline evidence, not proof that all
  corresponding Durable driver actions have been ported.
- Whole-repository Mago formatting check passes. Lint has no errors or warnings,
  with two stylistic help messages about cleanup loops. Analysis has no errors or
  warnings: three existing help messages and one about deliberate registration
  validation. QA components ran separately, with all tests using `bin/test.sh`.
- Four deliberate implementation breakages were observed failing: omitted outbox
  publication, propagated initial scheduling rejection, omitted final mutation
  ownership check, and bypassed Activity journal fencing. All were restored;
  the four focused restored controls passed (29 assertions), followed by full QA.
- The suite's incomplete contracts remain incomplete. PHP 8.5 deprecations from the
  external Lerd collector remain visible. They are not relabeled as clean passes.

The new Operations source totals 2,615 physical lines, including documentation,
blank lines, models and the retained historical engine. The application change
(including Integration connection configuration) adds 1,941 and removes 89 lines
relative to the original spike checkpoint. This is **not** a demonstrated net
infrastructure reduction versus a feature-equivalent Ecotone department. The
connection-config wrapper was actually removed; deleting the old runtime must
wait for the remaining accepted delivery/fencing coverage and real domain adapter.

Recommendation: keep PR #83 as the working draft and continue from this usable
admission/execution/handoff foundation. Do not replace the Ecotone direction or
merge/deploy until the outstanding acceptance and canonical publication gates
above have been satisfied.

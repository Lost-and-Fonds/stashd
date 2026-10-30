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
promotion increments delivery generation and stores a replacement outbox delivery
in the same transaction. Obsolete generations cannot claim an Attempt. Running
promotion records desired priority without interrupting the existing Attempt.
The real Messenger worker checks the interactive transport before the routine
transport at each dequeue. This is queued preference, not preemption or a fairness
guarantee under sustained load.

Bind a domain `WorkExecutor` when constructing the runtime (or through Tempest's
container). Its public method is now
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

Run `integration:init` before using the upgraded runtime. The additive Tempest
migration preserves existing Jobs, messages, keys and counters. New Attempt rows
record actual starts and completion/failure; recovery closes an unfinished prior
Attempt as interrupted. Existing historical counters are not backfilled into
invented Attempt records. Roll out the migration before starting upgraded workers.

## Transaction choice

The installed Tempest 3 database documentation, `GenericDatabase`, connection
interface, transaction manager and schema statements were inspected. Tempest
continues to own schema migration and validation rules. Its default connection is
separate from Messenger's Doctrine connection; its transaction manager does not
enlist that other connection. Its query builder does not express the PostgreSQL
transaction advisory lock or active-only uniqueness guarantee used here.

The existing runtime therefore keeps its single DBAL connection for admission,
claim/Attempt changes, completion and native Doctrine outbox writes. This is the
existing department boundary, not a new general persistence framework. No other
Tempest writes are claimed to participate. Introducing a shared adapter across
all Integration services would broaden this pass and change their transaction
ownership. Revisit this choice when a supported Tempest/Messenger connection
integration can preserve the same atomicity without that broad change. Initial
admission and priority promotion both have real outbox-rejection rollback tests.

Independent review also inspected the installed `GenericCommandBus` and Tempest
event-bus documentation. They dispatch synchronously and do not provide this
durable transport/outbox or its retry and acknowledgement protocol. Messenger's
own Symfony dispatcher is used only for its worker lifecycle subscribers, not
for domain events. Replacing those subscribers with a Tempest adapter would add
infrastructure without improving atomicity. Revisit this boundary if Tempest
provides an equivalent durable transport integration; domain events remain owned
by the EventManagement catalogue and Tempest event facilities.

## Evidence and remaining limits

PR #75's six test-only files were originally imported unchanged from
`99b8ae6693744bd4b1ec29ad4d044323eef5d9c7`. Independent verification subsequently
formatted only `tests/Pest.php` (import order) and
`tests/Acceptance/Operations/WorkAdmissionTest.php` (assertion-chain line wrapping).
Those two files now differ byte-for-byte from PR #75; assertions, fixtures,
semantics and negative controls are unchanged. The other four remain identical.
`phpunit.xml` installs the real driver;
the acceptance assertions and their fixture port remain independently authored.
The driver uses disposable PostgreSQL databases and restricted roles, actual
Tempest migrations, fresh processes, real Messenger outbox relay/workers and
independent SQL observations. The admission barrier releases children only after
PostgreSQL reports every child waiting in an active admission transaction. Worker
processes have deadlines and are stopped during cleanup. Setup failures fail tests.

The private `tmp/transaction-projection-admin.json` is local and ignored. Only
fixture provisioning uses it; workers receive generated restricted credentials.
Never commit this file or use application databases for these scenarios.

Claim renewal, fencing live expired workers and external effects, crash-safe
exactly-once domain effects, cancellation, comprehensive recovery and scheduling
fairness remain unproven. An effect can occur before completion persistence fails;
a recovered Attempt can then repeat it unless the domain independently protects
that effect. Job completion alone is not domain-outcome evidence. Existing
EventManagement vocabulary on `dev` is not duplicated here; comprehensive emitter
integration is deferred. Independent verification is still required.

## Resumption provenance

The original worktree is
`/home/hazel/Projects/stashd/core/core-feat-operations-vertical-slice`, branch
`feat/operations-vertical-slice`, initially clean at `2182e316`. PR #74's remote
head `d8a7f6b1` adds the `dev` parallel-test merge; local `dev` at `8c6dc0b6`
also contains merged EventManagement PR #76. At initial inspection, the original branch was one commit ahead and five behind
that local `dev`. The subsequent PR update retains the existing remote #74 merge
history without merging the newer EventManagement changes.

The separate `core-feat-operations-work-admission` tree at PR #75's commit contains
unfinished uncommitted connection adapters, Work types, a migration and dependency
changes. They are preserved there, not used as this implementation baseline.
The abandoned `core-feat-operations-durable-admission` tree is also untouched.

## Validation from this resumption

- `STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Operations/WorkAdmissionTest.php`:
  exit 0, four passed, 68 assertions. All six imported files were subsequently
  compared byte-for-byte with PR #75's source commit.
- Existing `JobsTest.php`: exit 0, seven completed with 191 assertions; Lerd's
  collector marks them deprecated under PHP 8.5.
- `WorkTest.php` and `WorkAdmissionAtomicityTest.php`: exit 0, eight passed and
  two marked deprecated, 61 assertions. These cover invalid input, initial
  admission rollback, promotion rollback and running coalescence.
- Five deliberate negative controls failed as intended: removed coalescing
  (exit 2, unique constraint), collapsed modes (exit 1, distinct-ID assertion),
  ignored priority routing (exit 1, next-execution assertion), reserved terminal
  Work (exit 1, new-ID assertion), and missing effect (exit 1, effect assertion).
  All modified source was restored after each control.
- `STASHD_TEST_PROCESSES=8 ./bin/test.sh`: exit 1, 428 passed, nine deprecated,
  33 incomplete, one failed, 23,566 assertions in 116 seconds. The sole failure
  was the existing configuration-upgrade test's exact migration count (six,
  now seven). That fixture expectation was updated; its focused rerun passed
  with 34 assertions and exit 0. The full suite was not repeated after that
  one-line fixture update. Incomplete contracts concern Records & Archives.
- Mago lint: clean. Full analysis: only three pre-existing Records & Archives
  help messages. Implementation/driver formatting and `git diff --check`: clean.
  Full formatting check exits 1 for the untouched PR #75 assertion layout and
  `tests/Pest.php` import order. They were preserved as instructed.
- QA components were run separately instead of the mutating `composer qa`
  chain, preserving the independent specification and using the canonical test
  wrapper. No dependency versions changed.

Detailed test output is retained locally in `/tmp/stashd-operations-*.log`;
negative-control results are in `/tmp/stashd-operations-negative-controls.json`.
These are implementer checks, not independent verification or certification of
Operations beyond this bounded slice. The implementation was initially left
uncommitted, then prepared for PR #74 following explicit authorization to update
the PRs. PR #75 retains its independent tests-only branch. Review the new `Jobs::request`, `Work` and `WorkExecutor` contracts,
retained-observation semantics, and strict queued priority before adopting them
in other departments.

The final preservation check also found existing uncommitted dependency changes
and untracked `app/Operations/CreateJobsTable.php` / `JobStore.php` in the abandoned
`operations-durable-admission` experiment. They remain untouched in that tree.


## Independent review decisions and evidence

The verifier began with a clean implementation worktree on
`feat/operations-vertical-slice` at `c28d8d462170ef7ac9d02953da290fbb023047f5`.
GitHub independently reported the same PR #74 head and PR #75 at `99b8ae6`.
The remote is `https://github.com/Lost-and-Fonds/stashd.git`. No merge, rebase,
pull, commit or push was performed. The acceptance and abandoned experimental
worktrees retain their unrelated dirty state.

Findings were presented before implementation changes, in severity order:

1. Active coalescence discards newer observations (`JobRuntime::admitWork`,
   active-Job return). Without durable departmental demand and reconciliation,
   the discarded revision can remain unprocessed indefinitely. The specification
   owner approved the mandatory departmental obligation documented above, with
   acceptance proof required before the first real integration. No Operations
   pending-observation policy was added.
2. A Job ID alone cannot distinguish retries or correlate an executor's effects
   with an Attempt (`WorkExecutor::execute`). The specification owner approved the
   immutable descriptive context above. The runtime now passes the persisted
   Attempt/claim identity and initial database lease deadline. This deliberately
   changes the pre-adoption executor signature; both in-tree implementations use
   it. A real PostgreSQL/Messenger test observes matching metadata and distinct
   Attempts across failure and retry on one Job.
3. Identifier syntax does not validate supported operation semantics (`Work`),
   so namespaces and departmental type/mode validation remain caller duties.
   The canonical tuple and partial index include mode; observations correctly
   remain outside identity. Advisory hash collisions can reduce concurrency but
   cannot coalesce distinct tuples. Completed and failed Jobs release the active
   reservation; legacy permanent keys retain their separate contract.
4. Non-blocking maintenance remains: older Operations declarations lack required
   PHPDoc, `JobServices` shares the runtime file, and Messenger 8.2 development
   dependencies require upgrade care. No dependency churn or broad reorganization
   was introduced in this verification pass. Tempest exceptions were checked
   against installed code and clarified above.

Real admission and priority-promotion rejection tests demonstrate rollback of Job
and outbox changes on one DBAL connection. The independent driver overlaps actual
PostgreSQL transactions and observes separate durable effects. The installed
Messenger worker polls interactive before routine delivery; priority is not merely
an SQL field. Promotion generations and terminal state prevent obsolete deliveries
from starting another Attempt under the tested conditions.

All five original negative controls were independently rerun against the final
API and failed for the expected reason: removed coalescence (unique constraint),
collapsed modes (same ID), ignored routing (wrong next Job), retained terminal
reservation (same ID), and missing effect (empty effects). Two additional controls
removed the advisory lock (concurrent admission unique violation) and generation
guard (an obsolete delivery consumed a second Attempt). A third supplied the Job
ID as context Attempt ID (persisted-claim mismatch). All eight controls were
restored completely before positive reruns. The original coalescence control
alone does not isolate advisory locking; the additional lock control does.

Still unproven: live expired-worker fencing, exactly-once external effects,
renewal, cancellation, sustained fairness, comprehensive recovery and event
emitter durability. Context grants none of these. EventManagement PR #76's
catalogue was inspected on `dev` without merging it; combined-branch integration
was not executed. Departmental reconciliation is a required future integration
proof, not a capability demonstrated by the generic probe.

Final independent positive checks, after all mutations were restored:

- Work unit and atomicity checks: exit 0, eight passed, two collector-deprecated,
  61 assertions.
- Four independent PR #75 admission contracts with the real PostgreSQL driver:
  exit 0, four passed, 68 assertions.
- Existing Job regressions plus the new context integration test: exit 0, eight
  collector-deprecated tests, 219 assertions (191 existing + 28 context).
- Final canonical `STASHD_TEST_PROCESSES=8 ./bin/test.sh --colors=never`:
  exit 0, 429 passed, ten deprecated, 33 incomplete, 23,622 assertions in 121.00
  seconds. The extra deprecation is the new context test encountering the same
  Lerd `ReflectionProperty::setAccessible()` collector warning. The migration
  fixture correction passes in the complete suite. Incomplete tests remain
  Records & Archives contracts.
- CI commands `composer github:fmt`, `composer github:lint` and
  `composer github:analyse`: exit 0. Formatting/lint are clean; analysis retains
  only the three existing Records & Archives help messages. `git diff --check`
  passes. The mutating `composer qa` chain was not used.

Logs are local at `/tmp/stashd-independent-*.log`; all eight mutation results are
in `/tmp/stashd-independent-negative-controls.json`. No new dependencies or
migrations were introduced. The branch still has uncommitted review changes;
PR #74's remote head and failed CI run are therefore unchanged. The bounded slice
is ready for review once these changes are approved for commit/push and CI is
rerun successfully. Broader Operations guarantees and departmental integration
proof remain outside this approval.

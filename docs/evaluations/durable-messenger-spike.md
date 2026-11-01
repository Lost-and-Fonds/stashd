# Durable + Messenger spike

## Recommendation

**No-go for replacing Operations as-is; promising execution substrate.** This is a
runnable, bounded investigation, not a completed departmental implementation.
Persisted execution works without a Symfony kernel. Session locks solve ordinary
blocking activity ownership without a heartbeat supervisor. Neither expiring
claims nor session claims alone give authority to mutate canonical Vault data.
The spike reproduces both outstanding failure modes instead of declaring the
old acceptance suite satisfied by a new backend.

Branch: `spike/operations-durable-messenger`, based exactly on
`65eb0388378d827e8874162c2255722e7c3a4e99`. Original checkout, other branches,
workspace submodule pointer and frozen PR #82 are untouched. No deployment or
upstream/vendor patch. All database mutation is confined to generated fixtures.

## Reproduce

Use the existing private `tmp/transaction-projection-admin.json` fixture setup
(documented in `docs/development/durable-delivery.md`). It must contain the local
PostgreSQL test administrator DSN, username and password. It is ignored and must
never be committed. The new tests create a random `stashd_tx_test_<hex>` database
and unprivileged owner, pass only that owner's credentials to worker children,
and drop that database and role afterward. They require PostgreSQL and PHP 8.5
in the configured Lerd environment, not a mock database.

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Feature/Durable
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/OperationsDelivery tests/Acceptance/Operations
```

`tests/Support/Durable/worker.php` is a test-only fresh PHP process probe; its
input arrives on stdin. It uses Tempest's process executor and Messenger's actual
Worker, Doctrine transport and PHP envelope serialization. Never run this
against a non-test installation. The SQL effect table deliberately has **no
uniqueness constraint** so duplicates cannot be hidden by the fixture.

## Baseline

Restored the historical Composer manifest and lockfile, ran `composer install`,
then ran the second command above before adding Durable: **11 passed, 245
assertions, 18.40 seconds**. This covers all seven delivery contracts and four
admission contracts present at the starting commit. Initial attempts failed due
to missing private fixture configuration, then incorrect administrator username;
those were environment failures, not runtime regressions.

The later OPS-C03 tests were inspected read-only from
`origin/test/operations-lease-fencing-contracts`. They were not imported, adapted,
or claimed passing. Their stale checkpoint/custody/result requirements remain
an adoption gate. PR #80/#81 metadata was read for scope; there is no Ecotone code
or dependency in this branch.

## Dependency and integration inventory

Composer pins the actual resolved development revisions in `composer.lock`:
Durable `f557f73`, Bundle `bd7b216`, DBAL bridge `713fc98`, Messenger `840deb2`.
FrameworkBundle is installed transitively; there is no Symfony kernel.

Directly reused:

- DBAL: `DurableSchema`, `DbalEventStore`, `DbalWorkflowMetadataStore`,
  `DbalChildWorkflowParentLinkStore`, `SingleResumeLockMiddleware`,
  `LockActivityAttemptClaim`.
- Durable: `ExecutionRuntime`, `ExecutionEngine`, `WorkflowDefinitionLoader`,
  `WorkflowRegistry`, `RegistryActivityExecutor`, `ActivityMessageProcessor`,
  `ResumeWorkflowHandler`, `NullActivityHeartbeatSender` and their journal/replay
  implementation. No custom backend, interpreter, journal or message transport.
- Bundle: `MessengerActivityTransport`, `MessengerWorkflowResumeDispatcher`,
  `MessengerWorkflowTimerDispatcher`, `EarlyResumeMiddleware`, `ActivityRunHandler`.
- Messenger: `MessageBus`, `Worker`, Doctrine transports, sender/handler locators,
  `DispatchAfterCurrentBusMiddleware`, native retry and failure listeners.
- Symfony Lock: `PostgreSqlStore` for session ownership; `PdoStore` only in the
  adversarial expiring-claim experiment. Existing DependencyInjection
  `ServiceLocator` supplies finite Messenger services without copying a container.

Application-owned classes are deliberately limited to:

| Class | Why owned |
| --- | --- |
| `DurableConnections` | Explicit separate application, journal and claim handles; lifecycle belongs to the host |
| `DurableRuntimeInitializer` | Tempest initializer chooses upstream session lock store; no second framework boots |
| `DurableRuntime` | Central finite service graph and worker setup normally supplied by the Bundle's DI registration |

The Bundle's CoreServices, DbalStores, MessengerServices, ClientServices and
RegisterDurableMiddlewarePass were read before wiring. Middleware keeps early
resume outside single-resume locking, then dispatch-after-current-bus, send and
handle. The runtime never uses MessengerActivityTransport's eager-ack dequeue;
Messenger Worker owns acknowledgment. The timer dispatcher is wired, but timer
handler execution and child workflow execution are not implemented in this
bounded slice. Do not use them through this runtime yet.

Workflow registration uses upstream `registerClass`; activity handlers use the
upstream registry. Tests resolve the runtime through a real Tempest initializer.
Full application boot, automatic workflow/activity discovery and a normal
Tempest worker CLI remain **incomplete**. Fresh worker probes construct the same
runtime explicitly. This is not a claim to have completed phase 2's discovery.

## Evidence matrix

Green characterization tests can demonstrate a **failed requirement**. The two
columns below deliberately distinguish these meanings.

| Required behavior | Evidence | Requirement verdict |
| --- | --- | --- |
| Baseline admission/rollback, duplicate admission, priority | Original four admission contracts, real PostgreSQL | Pass on old runtime only |
| Baseline outbox/restart/relay death/worker post-completion death | Original seven delivery contracts, independent processes | Pass on old runtime only |
| Basic persisted activity execution | Tempest-resolved runtime, real Messenger worker, SQL effect and completed metadata | Pass for one activity |
| Replay across restart | Three fresh PHP workers: schedule, activity, resume; persisted history and effect | Pass for orderly process exits |
| Duplicate activity delivery | Redeliver actual ActivityMessage after completion; one effect | Pass for settled redelivery |
| Enqueue failure after journal append | Real PostgreSQL trigger rejects activity INSERT; scheduled and failed journal events retained | Failure detectable; no automatic recovery |
| Operator recovery | After rejected enqueue, remove fault, start replacement execution; one effect, completed metadata | Pass only where original activity was never delivered |
| Long blocking activity | One-second claim TTL exceeded; independent claimant denied | Pass with session store; no fifteen-hour soak |
| Competing ownership | Paused live worker holds claim; separate connection cannot claim same attempt | Pass at upstream claim boundary; full duplicate-message race not proven |
| Killed activity worker | SIGKILL exit 137, session claim reacquired within bounded polling, native visibility expiry, fresh worker completes | Pass in local controlled process death |
| Stale completion with expiring store | Live paused activity outlasts TTL; second worker completes; old worker then appends another ActivityCompleted | **Fails**: two completions and two effects |
| Lost session with live worker | Terminate exact lock backend, second worker completes, release old worker | **Fails domain safety**: two effects; only one completion in this wiring |
| Stale Vault/checkpoint/custody/result writes | SQL effect is an unsafe-boundary demonstration, not a Vault contract implementation | Incomplete |
| Atomic business-model + workflow admission | Not implemented through Tempest models | Incomplete |
| Failed transport replay, failure-budget ownership, renewed TTL versus takeover | Native listeners wired; no new accepted contract adaptation | Untested on Durable |
| Random crash specifically between journal and activity enqueue | Enqueue exception tested; this exact SIGKILL window not injected | Untested |

## Journal-to-queue finding

Upstream EventStoreCommandBuffer appends ActivityScheduled before enqueue.
The fault trigger runs on the real Messenger activity queue INSERT, after the
journal write on another connection has committed. WorkflowFiberDriver classifies
the resulting exception as WorkflowExecutionFailed. The resume handler removes
active metadata. Messenger's retry listener retains the failed resume for retry,
but a later resume does not recreate the missing activity or its metadata.
No activity effect occurs. The test checks the persisted event types, absence of
activity envelopes, retained resume invitation, and ineffective explicit resume.

An operator can detect the failed journal and start a replacement execution.
The test demonstrates that path only for a rejected INSERT where no activity was
ever delivered. It does **not** establish that arbitrary failed workflows are
safe to restart: previously completed or remote side effects need idempotency.
No automatic detector or operator command is implemented; journal inspection is
the current diagnostic. This falls short of a general recovery facility.

A small upstream scheduling correction is preferable: distinguish delivery
failure from workflow algorithm failure, preserve resumable state, and reissue
unsettled scheduled activities safely. Merely retaining metadata would still
leave replay waiting on an already recorded scheduling event. A transaction
around an entire pass is unsafe; a Messenger outbox on the application connection
cannot atomically commit the journal. A short journal-and-delivery-intent
transaction or upstream reconciliation would need an explicit upstream contract.
Expected scope: a focused upstream change and crash/retry tests, not another
Stashd backend. Healthy-path exposure duration was **not measured**; no
millisecond estimate is claimed.

## Long activity finding

The existing claim interface can use Symfony's PostgreSqlStore unchanged.
Advisory locks belong to a session rather than an expiring row. A blocking
activity needs no renewal loop. The test exceeds the configured TTL while the
worker remains paused, verifies contention, kills and reaps it, and reacquires
within a three-second bound. Native message redelivery uses a one-second test
visibility timeout with a 2.1-second wait for timestamp precision. There are no
manual ownership-row edits. Production timeout/keepalive sizing is not supplied
by this spike and these intentionally short values are unsuitable defaults.

But a disconnected lock session releases ownership while PHP can remain alive.
The test terminates only that recorded backend and keeps the worker paused.
Another worker produces the result. The old worker then writes a second SQL
effect. In the session-store configuration, its pre-completion resume dispatch
encounters the dead lock connection, preventing its second ActivityCompleted.
This incidental protection is **not a fenced domain write**.

The expiring PdoStore control is worse: after the one-second TTL a second worker
runs; the stale worker then writes its effect and appends a second completion.
The claim interface supplies no authority token or before-commit validation.
Workflow-pass epochs do not fence these activity-side appends.

Simplest viable direction: session claims for execution exclusion plus retain
Stashd's canonical publication guard/idempotency at the actual Vault boundary.
A claim-aware before-completion check upstream would help journal integrity;
it cannot make remote effects exactly-once. A supervisor is unnecessary for the
ordinary blocking case, but neither supervision nor a token pre-check alone
makes a later filesystem publication atomic with ownership. The existing host
publication contracts must decide that boundary. Benefit is high; completing
that integration and its adversarial tests is nontrivial and remains unfinished.

## Tempest transaction ownership

Inspected installed ConnectionInitializer and PDOConnection: this historical
version constructs its own PDO connection and does not expose PDO injection.
No Ecotone adapter was copied. Upstream Tempest fix `5e56e440...` was not applied
or tested here. Business-model admission and the shared application transaction
remain incomplete; do not claim baseline DBAL-only admission proves Tempest model
atomicity. The journal is already a separate DBAL connection, following
[DUR054](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR054-the-journal-does-not-share-the-applications-connection.md).
DBAL here is justified by the library's required store API and fencing. Test SQL
is confined to rejection triggers, session fault injection, provisioning and
independent effect observations. Ordinary production models were not replaced.

## What can be deleted, and what cannot yet

The historical `JobRuntime.php` is 358 lines including its local service locator;
the six top-level Operations files total 500 lines. The later local
`feat/operations-attempt-fencing` JobRuntime is 270 lines, **not** the total cost
of the Ecotone direction. These are physical line counts including documentation,
not a feature-equivalent savings benchmark.

Durable can plausibly replace workflow replay, journal persistence, activity
orchestration/retry decisions and run history. Messenger already owns transport,
worker, outbox forwarding and delivery retries in the baseline. No deletion is
safe yet: canonical admission/coalescing, typed priority and the Vault write
authority boundary are still Stashd responsibilities. No old tables or runtime
were removed, and no production workload is routed to this experiment. The
side-by-side files exist only for comparison in the isolated spike.

A complete comparison needs the accepted contracts adapted to Durable, the cost
of canonical fencing, discovery and admission included. This branch cannot
honestly claim that it deletes most Operations infrastructure. It also cannot
support a go decision based on fewer lines at unequal guarantees.

The smallest next path is: fix/recover scheduling upstream; prove canonical
publication fencing with session claims; share Tempest model/admission enqueue
transactions; adapt OPS-C02/C03 without weakening assertions; then add typed Job,
Assignment and priority enum around upstream workflow identity. Implementing the
public facade before those gates pass would conceal unresolved behavior.

## Validation record (2026-11-01)

- Exact historical lockfile: 11 baseline contracts passed, 245 assertions.
- After dependency installation: combined run had 11 baseline passes plus six
  successful Durable characterizations (280 assertions total at that revision).
- Final Durable-only run after unprivileged fixture roles, Tempest initialization
  and explicit effect assertion: **six characterizations, 36 assertions,
  11.89 seconds, exit 0**. Pest labels these `deprecated`, because Lerd's external
  `/usr/local/etc/lerd/devtools-collector.php:644` calls ReflectionProperty's
  deprecated `setAccessible()`. They are not six clean warning-free test passes.
- Negative control: temporarily changed ProbeWorkflow to return its input without
  scheduling an activity. The basic execution test failed at its SQL effect
  assertion (`['preserved']` versus `[]`, exit 1). Restored the workflow and ran
  the full six-test slice again. No intentional breakage remains.
- Mago formatting check and lint pass for all added PHP. Mago analysis passes for
  the three new application classes. Whole-application analysis reports three
  existing help messages in ItemArchive and PreservationTransaction, no errors.
- `git diff --check` passes. No full repository test run or full `composer qa`
  is claimed; tests used the canonical wrapper, and formatter/linter checks were
  scoped to avoid unrelated historical-code edits.
- Composer audit reports existing locked PHPUnit and Symfony Mime advisories
  (`CVE-2026-119193`, `CVE-2026-45070`); these packages were not updated by this
  spike. This branch is not a deployment candidate.

The integration is 208 physical application lines across three classes,
including documentation. This count excludes fixture code and **does not**
include the unfinished admission/discovery/publication integration. There is
one journal persistence mechanism, Messenger's queue persistence and a PostgreSQL
session ownership mechanism in the experimental runtime; it adds no attempt
ledger or renewable lease supervisor. The historical engine remains present
and is only exercised by its original baseline tests.

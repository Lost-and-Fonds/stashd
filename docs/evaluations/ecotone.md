# Ecotone architectural replacement evaluation

## Recommendation: Reject the current candidate

Ecotone is promising for local database delivery and independent event subscribers,
but this spike is **not an accepted Operations replacement**. Reconsider adoption
with upstream changes after completing the missing failure tests below. Do not
ship this branch. A passing Work admission slice is not durable-delivery acceptance.

The strongest simplification is real: a PostgreSQL-backed Ecotone channel is both
committed intent and the consumer's queue. Stashd does not need an outbox-to-local-
PostgreSQL relay. This removes a process and a delivery boundary. It does not remove
Work identity, departmental reconciliation, Job/Attempt history or lease policy.

The evaluated release also loses queue priority on publication. A one-line isolated
upstream DBAL fix makes the unchanged priority contract pass. Tempest PR #2326
allows a reflection-free connection integration, but the existing Ecotone Tempest
adapter does not use that integration. Neither upstream change can be assumed
available to production.

## Isolation and provenance

- Evaluation date: 2026-10-31.
- Repository: `Lost-and-Fonds/stashd`.
- Fetched `origin dev`: **65eb0388378d827e8874162c2255722e7c3a4e99**.
- Branch: `spike/ecotone-evaluation`; worktree: `core-spike-ecotone-evaluation`
  inside the existing Core checkout.
- Original local `dev` remains at `8c6dc0b6ecf74ab4782c7c3196457103a03b1a39`.
  No switch, pull, reset, merge, push or PR was performed. Other worktrees were
  preserved, including the pre-existing Operations experiments. Workspace submodule
  pointers were not edited.
- Lerd initially reported its watcher stopped. Its worktree tool created the tree;
  Composer was run explicitly and `worktree wait` subsequently reported provisioned.
- Installed baseline: PHP 8.5 environment, Tempest **3.19.2**, DBAL **4.5.0**,
  Messenger **8.2.x-dev**. Candidate: `ecotone/dbal`, `ecotone/ecotone` and
  `ecotone/enqueue` **2.0.0-beta.7**.
- Additional upstream source inspected: ecotone-dev
  `bba9fa69dc999a75f709d5f3e913f178af40098d`. Behavioral claims below use installed
  package source unless identified as documentation or inference.
- Tempest PR #2326 was open and unmerged when inspected, head
  `5e56e440298e4c30e8059030ef078e7a71e4fbf6`. Only its three database production-file
  changes are copied into `tools/evaluations/tempest-2326.patch`. The upstream PR
  and its checkout were not modified. No framework patch is silently in the lockfile.

Design inputs read: Core `AGENTS.md`, installed Tempest database and connection
implementation, `docs/development/jobs.md`, `work-admission.md`,
`durable-delivery.md`, OPS-C01/OPS-C02 README specifications, existing EventManagement
middleware/catalogue, and the upstream sources linked below. This note supplements
those bounded contracts; it does not redefine them.

## Specification contradiction

OPS-C02 explicitly requires retained outbox envelopes, destination envelopes,
physical relay forwarding order and death after forwarding before acknowledgement.
Its action dictionary even specifies native Messenger visibility manipulation.
There is no corresponding intermediate relay boundary when the committed database
channel is consumed directly. Retaining an unnecessary relay just to satisfy these
assertions would defeat the architectural experiment.

All acceptance assertions and specifications remain unchanged. Only the OPS-C01
implementation driver was adapted: initialize the native channel before concurrent
admission, consume Ecotone directly in fresh PHP processes, and inspect `enqueue`
instead of `messenger_messages` when checking settlement. Effects and Jobs remain
independently observed. OPS-C02's existing Messenger driver was run unchanged and
fails all seven cases; that is a recorded failure, not a claim of seven passes or
seven incomplete tests. A backend-neutral revision requires specification-owner
review. Transaction rejection, committed-intent restart and consumer-death semantics
still apply even though the relay-specific observations do not.

## Mapping and actual implementation

| Responsibility | Messenger baseline | Ecotone candidate / status |
| --- | --- | --- |
| Admission API | `Jobs::request`, `Work` | Same contracts and validation |
| Canonical active reservation | Job partial uniqueness and transaction advisory lock | Retained; no native bus facility replaces domain identity |
| Priority promotion | Generation increment and interactive transport invitation | Same generation rule, native message priority; needs upstream fix |
| Durable invitation | `OutboxSender`, `OutboxStamp`, Doctrine outbox | Direct DBAL-backed `jobs` channel on the admission connection |
| Local delivery | Outbox relay plus destination worker | One native Ecotone consumer; no local relay |
| Execution | `ExecuteJob`, `WorkExecutor`, `ExecutionContext` | Retained payload and executor/context contracts |
| Attempts, expiry, completion | DBAL claim and token/expiry checks | Retained domain logic; not replaced with an opaque framework receipt |
| Retry after executor failure | New generation and delayed invitation, max three failures | Retained domain retry policy |
| Transport retries/failure queue | Messenger listeners, multiplier strategy and failed repository | Native facilities exist; NOT configured/proven by this spike |
| Events | Three undiscovered Tempest middleware classes | Three independent native async subscribers demonstrated in test code |
| Vault / OCFL | Authoritative preservation | Untouched; not enlisted in a PostgreSQL transaction |

`JobRuntime.php` no longer constructs Messenger envelopes, senders, locators,
Doctrine transports, middleware, worker event dispatcher or lifecycle subscribers.
Its nested `JobServices` PSR container is deleted. `retryFailed` and the
`job:retry-message` command are removed rather than pretending to replay Ecotone
failures through Messenger. `job:work jobs` remains; `job:work outbox` now rejects
that receiver explicitly. Existing migration history is retained: no historical
Jobs, Attempts or envelopes are deleted by a migration in this experiment.

New application class: `TempestDbalConnection`. New proof code:
`EcotoneTransactionTest`, `CatalogueSubscribers`, and `event-consumer.php`.
Two reviewable upstream patches are stored under `tools/evaluations/`.
The adapter is exercised through the real Tempest `DatabaseInitializer`; it is
not yet installed globally in the application's `OperationsDatabase` binding.
That distinction prevents claiming that every existing Tempest persistence path
now participates automatically.

The spike uses EcotoneLite **bootstrap**, not its flow-testing/in-memory bootstrap.
The queue is actual PostgreSQL; execution uses real Ecotone consumers in separate
PHP processes. The service-container array is dependency registration, not a queue
or fake persistence layer.

## Transaction integration

### Verified

The preferred direction is DBAL ownership of one connection object, with Tempest's
public `Connection` interface implemented using DBAL's public
`getNativeConnection()` API. No private PDO reflection is necessary. The adapter
prepares PDO statements because Tempest's interface explicitly returns
`PDOStatement`; transaction begin/commit/rollback go through DBAL to preserve its
nesting state. It re-resolves the PDO rather than caching a handle.

With the isolated PR patch, `DatabaseInitializer` honors that injected singleton
and passes configuration separately to `GenericDatabase`. The real test proves:

- Tempest mutation and `Jobs::request`/native channel insertion commit together.
- Explicit rollback removes both; a rejected real queue insert removes the Job and
  Tempest mutation; a subsequent Tempest unique violation removes the enqueue too.
- Independent SQL cannot see uncommitted rows.
- Tempest and DBAL queries observe the same PostgreSQL backend PID and transaction ID.
- Reconnect outside a transaction changes backend PID; reconnect inside a transaction
  is refused. A nonpersistent Tempest configuration is used.
- Removing the PR patch fails the injected-connection identity assertion. Restoration
  returns the positive proof to passing.

### Not established

The upstream Ecotone Tempest resolver still uses `ReflectionProperty('pdo')`.
Its dynamic driver also accesses that private property, and its PSR container
adapter reflects container internals. PR #2326 alone does not fix these paths.
The spike deliberately uses Ecotone's supported explicit DBAL connection registration
instead of installing that adapter.

Application-wide binding order, tagged connections, container reset and multiple
long-running consumer reconnect cycles need further proof. Register the singleton
before resolving `Database`; replacing a connection after a `GenericDatabase`
has captured it does not update that object. There must be one transaction owner.
A shared PDO with independent transaction counters is not sufficient. Do not close
or reconnect an active transaction. No claim is made about automatic reconnection
following server failure or nested cross-framework transaction recovery.

Tempest remains the target for ordinary persistence and migrations. The retained
Operations SQL uses PostgreSQL advisory locking/conditional claims that motivated
its original exception. The spike does not introduce Doctrine ORM. Runtime queue
DDL is an experiment shortcut: the driver preinitializes it to avoid concurrent
CREATE races; production must provision channel tables in deployment, using a
Tempest migration around the vendor-provided schema, before consumers start.

## Queue behavior and recovery

**Verified installed source:** `DbalConsumerHelperTrait` selects by native priority
ascending, then publication time; `DbalProducer` negates application priority for
storage. The normal consumer uses conditional delivery-ID updates, not the
`FOR UPDATE SKIP LOCKED` batch-outbox path. Multiple consumers can race and retry;
this does not guarantee strict global completion order. Equal priority/time has
no explicit ID tiebreaker. No fairness or preemption guarantee is inferred.

**Verified defect:** `DbalOutboundChannelAdapter::handleBatch` omitted setting the
queue message priority even though its outbound conversion retained it. The patch
sets `setPriority($outboundMessage->getPriority())`. Without it the protected
promotion test fails at WorkAdmissionTest's next-Job assertion. With it all four
OPS-C01 cases pass. A different generic Enqueue sender has the same apparent
omission; no generic sender fix is retained because this slice uses DBAL's override.

**Verified source, not crash acceptance:** receipt writes `delivery_id` and
`redeliver_after`; acknowledgement deletes by delivery ID; expired deliveries become
eligible again. The low-level consumer defaults to a 20-minute redelivery delay,
which differs from Stashd's 60-second Job lease and Messenger visibility. These
settings must be deliberately aligned and tested. The event proof advances the
real delivery's visibility deadline to recover its failed client subscriber.

**Documented and source-present, not adopted here:** DBAL module transactions,
backoff retry configuration and database dead-letter list/show/replay/delete APIs.
This slice disables automatic transaction wrapping for the Operations handler
because the claim must commit before execution and the independently persisted
probe effect. Wrapping the entire executor would leave a long transaction and
change the established lease visibility contract. It disables framework dedup and
dead-letter storage for the bounded proof and stops on infrastructure errors.
This is a material missing production capability, not equivalent failure policy.
Framework message dedup would not by itself replace Job generation guards or
external-effect idempotency.

Local PostgreSQL delivery needs no relay or external broker. Adding RabbitMQ/SQS
later reintroduces a database-to-external-transport forwarding boundary. This spike
has no evidence for that topology or remote-broker outages.

## EventManagement evidence

One existing `FixityCheckPassed` catalogue class, already classified
`DurableHistory` and `PremisSignificant`, is published through Ecotone's event bus.
Its three native async endpoints receive distinct durable invitations. Rollback
leaves none; commit leaves three. Fresh PHP consumer processes record history and
PREMIS-significant fields. A deliberately failing client subscriber leaves those
records intact and retains its own invitation. After native visibility expiry and
restart, client delivery succeeds. All three records retain the original catalogue
`EventMetadata::eventId`; the client projection contains only `itemId` and `checkId`.

The fixture tables are independent subscriber evidence, **not authoritative Vault
PREMIS metadata**. No real fixity operation is invented by this proof. Existing
catalogue classes do not currently carry `ClientVisible` annotations; the small
subscriber's allowlist is test-only. Production classification, projection policy
and recorder bindings remain work. The test does not enable Tempest middleware
alongside Ecotone, so it demonstrates one bus path without double dispatch.

An adoption could replace the three middleware delivery hooks with native async
subscribers while reusing catalogue values, classifiers and client projection
policy. It must not publish every Tempest framework event automatically or give an
SQL event record authority over OCFL. No migration of the 181 catalogue events is
attempted. Independent per-subscriber failure handling is useful, but does not
solve authoritative preservation crash boundaries.

## Dependency and deployment comparison

| Item | Baseline | Actual spike / eventual target |
| --- | --- | --- |
| Direct dependencies removed | — | **None yet**; baseline Messenger packages retained for legacy verification drivers |
| Direct added | — | `ecotone/dbal:2.0.0-beta.7` |
| Transitive added | — | ecotone/ecotone, ecotone/enqueue, enqueue/dsn 0.10.27, queue-interop 0.8.2, laminas-code 4.18.0, Symfony DI 8.2.x-dev |
| Other lock changes | — | service-contracts 3.7.3 → dev-main; var-exporter 8.1.9 → 8.1.10 |
| DBAL | Direct 4.5.0 | Still direct in spike and transitively required by Ecotone; acceptable target dependency |
| Removable target dependencies | Messenger, doctrine-messenger, worker event-dispatcher/console setup | Remove after retiring all Messenger-specific code and verification drivers; not counted as actual removals |
| Domain tables | jobs, job_attempts | Same |
| Delivery tables | messenger_messages, queue names outbox/jobs/jobs_interactive/failed | enqueue; historical Messenger table remains from unchanged migration |
| Optional native infrastructure | — | deduplication/dead-letter/document-store tables as enabled; not all needed for this slice |
| Job processes | relay + worker | One `php tempest job:work jobs` consumer |
| Event processes | No production durable subscribers | One consumer per isolated channel or explicitly designed worker topology |

Composer initially required `-W` because adding Ecotone's Symfony DI conflicted with
partially locked Symfony versions alongside Messenger 8.2. The extra dev-main
constraint is a maintenance cost, not an advantage. Ecotone's Tempest package was
inspected but not installed, and no ORM was added. Composer reported two security
advisories during install; audit/advisory remediation was not part of this spike.

Native attribute discovery and container compilation introduce startup work and a
cache lifecycle. The experiment disables compiled-cache reuse. Production needs
release-specific cache warmup/invalidation, writable or prebuilt cache policy,
worker restart on deployment and compatible message serialization across releases.
Queue payload versioning and replay of old messages must be specified before a
migration. Do not feed Ecotone legacy PHP-serialized Messenger envelopes.

No CPU/RSS/throughput benchmark was run. Measured focused test runtime is about
11 seconds for five cases, dominated by process/fixture setup; it is not an
application throughput comparison. One fewer supervised Job process and one fewer
local delivery hop are concrete topology reductions. Package count and compiled
configuration complexity increase. A smaller JobRuntime diff alone cannot decide
net maintenance cost.

## Licence boundaries

Installed `Saga` is an Apache-2.0 stateful aggregate attribute. Basic command/event
handlers, channels, routing and the inspected DBAL channel/dead-letter code carry
Apache-2.0 (some inherited queue code MIT). Saga persistence still needs schema,
correlation and compatibility policy; it was not exercised here.

`OrchestratorModule` explicitly throws without an Enterprise licence. High-throughput
DBAL publication is also Enterprise-gated, as is the inspected InstantRetry
attribute module. Packages advertise Apache-2.0 and proprietary code together;
package availability is not permission to use every feature. No Enterprise key was
supplied in any proof. Do not base a planned durable workflow design on Orchestrator
without a licence decision. This is source inspection, not a legal opinion or an
exhaustive licence audit of every optional module.

## Verification and reproduction

From this evaluation worktree, install the locked dependencies through Lerd. Supply
an explicit private `tmp/transaction-projection-admin.json` for the existing safe
fixture allocator, as required by the acceptance driver. Discover local credentials
with Lerd's service env action; never use production administrator credentials.
Tests create restricted roles and disposable `stashd_tx_test_*` databases and tear
them down. The file is ignored and is not a deliverable.

```sh
lerd composer install --no-interaction --no-scripts
# Isolated upstream variants; these are NOT included by Composer:
git apply --directory=vendor/tempest/framework tools/evaluations/tempest-2326.patch
git apply --directory=vendor/ecotone/dbal tools/evaluations/ecotone-dbal-priority.patch
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Feature/EcotoneTransactionTest.php tests/Acceptance/Operations/WorkAdmissionTest.php
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/OperationsDelivery/DurableDeliveryTest.php
lerd composer github:fmt
lerd composer github:lint
lerd composer github:analyse
```

Patches are applied to this worktree's vendor tree at delivery. Use `git apply
--check` before reapplying; Composer reinstall may remove them. The Tempest patch
is pinned to PR head above, not an endorsed upstream release. Reverse either patch
with `git apply -R --directory=...` to reproduce its negative control, then restore
and rerun positives. Do not reverse patches while consumers/tests are running.

| Check | Observed result |
| --- | --- |
| Unpatched Ecotone OPS-C01 | 3 passed, priority case failed at unchanged next-Job assertion |
| Patched DBAL OPS-C01 | 4 passed, 68 assertions |
| Shared transaction + catalogue proof | Passed; commit, both rollback faults, PID/tx identity, independent consumers, failure and replay, reconnect |
| Combined positive run | 5 passed, 124 assertions after final formatting (10.61 seconds) |
| Remove Tempest PR | Failed at injected Connection identity; restored |
| Remove DBAL priority fix deliberately | Failed at protected priority ordering assertion; restored |
| OPS-C02 unchanged | 7 failed, 78 assertions; existing driver still expects Messenger envelopes/relay |
| Mago lint | Clean |
| Mago analysis | Only 3 existing Records & Archives help messages after spike errors corrected |

No full-suite green claim is made: Messenger-specific integration tests and the
OPS-C02 driver require deliberate replacement, and the candidate lacks the
transport failure policy those tests inspect. Existing acceptance assertions were
not formatted, weakened or deleted. Focused QA commands were used instead of the
mutating whole `composer qa` chain to preserve the independent specifications.

## Required work before reconsidering adoption

1. Land/release the Tempest connection fix and upstream the DBAL priority defect.
2. Specify backend-neutral OPS-C02 observations without weakening admission or
   consumer crash/replay requirements. Implement an Ecotone driver for applicable
   fault windows, using real restricted database faults and independent effects.
3. Prove consumer death before ack, native redelivery, obsolete generations,
   transport rejection/recovery and persisted Attempt outcomes under those faults.
   OPS-C01 promotion exercises obsolete invitations but is not every redelivery case.
4. Bind the shared connection before application Database resolution, test both
   initialization orders and long-running connection loss/recovery, and move queue
   provisioning into deployment/migrations. Preserve old delivery intent explicitly.
5. Configure native retry/dead letters, monitoring, replay authorization and lease/
   visibility settings; do not confuse transport retry count with domain Attempts.
6. Prove actual departmental PREMIS recording and client policy, then remove duplicate
   middleware delivery integration. Preservation authority remains in the Vault.
7. Complete dependency removal, runtime benchmarks, serialization/deployment policy
   and licence selection for any desired workflow features.

Live stale-worker fencing, claim renewal, cancellation, scheduling fairness,
exactly-once arbitrary external effects, and preservation crash safety remain
unproven. Some were already explicitly deferred by baseline Operations; the new
priority loss and missing Ecotone crash/recovery driver are replacement gaps.

## If Ecotone had been selected first

We would not have needed Stashd's Messenger service locator, envelope/stamp routing,
worker lifecycle listener assembly, local relay process, relay-to-local-queue hop,
or a bespoke Messenger failed-envelope redispatch method. Native async event
subscribers could have avoided building commit-aware delivery around Tempest's
three catalogue middleware hooks. We would still need connection ownership
integration; PR #2326 enables a cleaner one but does not supply Ecotone integration.

We would still need canonical Work identities, concurrent active coalescence,
Refresh/Complete semantics, queued promotion policy, Jobs and Attempts, generation
checks, leases, resource policy, reconciliation of durable departmental demand,
external-effect idempotency/fencing, EventManagement classifications and safe
projection rules. Vault/OCFL custody, fixity and recovery would be unchanged.
Ecotone can reduce messaging plumbing. It cannot replace these preservation and
Operations domain contracts, and this candidate has not yet earned adoption.

## Sources

- [Tempest issue #2325](https://github.com/tempestphp/tempest-framework/issues/2325)
  and [PR #2326](https://github.com/tempestphp/tempest-framework/pull/2326): injection
  defect and proposed fix; PR metadata and diff inspected directly via GitHub.
- [Ecotone source](https://github.com/ecotoneframework/ecotone-dev), including
  packages/Dbal and packages/Tempest; installed release source is authoritative for
  the observed priority defect and licence gates.
- [Tempest adapter](https://github.com/ecotoneframework/tempest): integration source,
  including private PDO reflection; contribution source lives in ecotone-dev.
- [DBAL documentation](https://docs.ecotone.tech/modules/dbal-support): documented
  native channels and transaction defaults; not a substitute for fault tests.
- [Motivation article](https://blog.ecotone.tech/ecotone-vs-symfony-messenger-the-real-difference/):
  architectural motivation only; no durability claim here relies on marketing text.

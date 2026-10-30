# Ecotone architectural replacement evaluation — round two

## Recommendation: Adopt after specific upstream work

The candidate now satisfies the bounded native Operations durability contracts
without rebuilding Messenger's local relay. Ordinary application persistence can
use Tempest Database on the same physical connection as Ecotone delivery through
normal Tempest bootstrap. Neither conclusion depends on an in-memory queue or a
manually constructed Tempest container.

Adoption remains conditional on releasing Tempest PR #2326 and fixing/releasing
Ecotone's native DBAL priority propagation. This branch is an evaluation, not an
authorized production migration. Existing queued-envelope migration, deployment
compatibility and operational rollout still need design. Do not merge this draft
as a production replacement.

[Round-one findings](https://github.com/Lost-and-Fonds/stashd/blob/c710a7f5481fa9f8ef012a006fcb19760865b5b1/docs/evaluations/ecotone.md) and its original evidence JSON are
retained. Its seven failures from the Messenger driver establish **neither**
success nor failure of the new architecture. The additional native contracts below
now test the necessary product outcomes directly. The round-one rejection is
superseded by this conditional recommendation.

## Provenance and scope

- Core repository `Lost-and-Fonds/stashd`, branch `spike/ecotone-evaluation`, draft
  PR #80. Base fetched dev: `65eb0388378d827e8874162c2255722e7c3a4e99`.
- Round-one commit: `c710a7f5481fa9f8ef012a006fcb19760865b5b1`.
- Existing local dev remains at `8c6dc0b6ecf74ab4782c7c3196457103a03b1a39`;
  other branches/worktrees and the workspace submodule pointer are untouched.
- PHP 8.5; Tempest 3.19.2; Doctrine DBAL 4.5.0; Ecotone DBAL/core/enqueue
  2.0.0-beta.7. No dependency or lockfile changes were made in round two.
- Isolated Tempest patch is pinned to PR #2326 head
  `5e56e440298e4c30e8059030ef078e7a71e4fbf6`; the separate native-priority patch
  is stored under `tools/evaluations/`. Neither is silently installed by Composer.
- Tempest adapter source inspection uses ecotone-dev
  `bba9fa69dc999a75f709d5f3e913f178af40098d`.
- No workflows, sagas, ORM or preservation implementation was added. Vault/OCFL
  remains entirely outside messaging transactions.

Inputs: unchanged OPS-C01/OPS-C02 specifications and drivers, their negative-control
verifier, Operations design notes, Core AGENTS.md, installed Tempest database/DI/
process facilities and installed Ecotone channel/retry/acknowledgement source.
Ecotone's official async, resiliency, testing, handler and interceptor skills and
documentation MCP were also consulted. Some documentation/skill examples use old
namespaces; installed 2.0 APIs determine the actual implementation.

## Contracts first: OPS-C02-E

The new [native specification](../../tests/Acceptance/EcotoneDelivery/README.md)
and `NativeDurabilityTest.php` are additional contracts. Original OPS-C01 and
OPS-C02 assertions, names and READMEs remain byte-identical to the fetched base.
The new test assertions query independent PostgreSQL state and effects; the driver
has no alternate Job store, fake queue, acknowledgement mechanism or completion
ledger.

| Original OPS-C02 | Native contract / independent outcome |
| --- | --- |
| Outbox rejection | Real enqueue constraint rejection rolls back Job; later Tempest mutation failure rolls back enqueue |
| Admission death | Deferred COMMIT trigger blocks after writes; terminate child/backend, observe no partial intent, readmit |
| Restart before relay | Observe committed Job and native row before launching a fresh consumer; one effect/Attempt |
| Forward destination failure | Real claim-write rejection, native delayed retries, exhausted dead letter, native replay of same Job |
| Relay forward-before-ack death | Local relay disappears; receipt/claim death tests the new durable execution boundary |
| Worker completion-before-ack death | Observe completed Job, independent effect and unacknowledged native row; kill/replay without another Attempt/effect |
| Reverse physical forwarding | No forwarding hop; old/new generation invitations and queued priority preference are observed directly |

Multiple consumers additionally overlap on a valid paused Attempt, duplicate
invitation and unrelated Work. The second consumer completes unrelated Work,
retains delayed recovery intent, adds no domain failure, and does not hot-loop.
Both real queue visibility and Job lease deadlines are advanced after terminating
the first consumer; recovery closes the old Attempt as interrupted and completes
under a new Attempt. Completed and obsolete deliveries are safe no-ops.

### Crash boundaries and explicit limits

Native `delivery_id`, Job/Attempt rows and separate effects are observed while the
child is alive. Executor pause occurs before its independent effect, after a
committed claim. A test-only native around-interceptor pauses after the real handler
has committed completion but before acknowledgement. An `After` interceptor on the
void handler did not establish that boundary and was discarded as evidence.
SIGKILL is sent to the exact PID and a signaled exit/137 is required; every child
has a deadline and is reaped before database teardown.

The precommit test blocks PostgreSQL with a deferred trigger, independently observes
a live waiting transaction, and terminates **both** the child and blocked server
backend before releasing the barrier. Client death alone cannot promise rollback
of COMMIT already received by PostgreSQL. Lost commit responses are ambiguous;
readmission/coalescing must handle either committed or aborted state. The proof is
server transaction abort before commit, not a claim about power loss/WAL/storage.

The effect-to-completion window remains: if an arbitrary external effect succeeds
and the worker dies before saving completion, recovery can repeat that effect.
The tests do not conceal effects with uniqueness constraints. No exactly-once
external effects, live stale-worker fencing, claim renewal, fairness or preservation
crash safety is claimed. Those were also deferred by the baseline Operations design.

## Busy claims and native error recovery

The round-one generic RuntimeException plus stop-on-error regression is removed.
A busy, valid claim is normal domain contention. It publishes a delayed invitation
on the **same native channel**, then returns normally. It adds no domain failure,
no Attempt and no new Job identity. Delay is 60 seconds, matching the bounded Job
lease. A crash before publication leaves the original native receipt recoverable;
a crash after publication but before ack can duplicate invitations, which the Job
guards tolerate. No separate busy queue or Messenger exception/listener is added.

The documentation MCP recommends delayed delivery and normal return for this case;
the behavior is also tested against actual DBAL messages. The second consumer's
subsequent bounded poll before the due time produces no extra Attempt/effect and
leaves the delayed row intact. The proof checks the native delayed deadline, not
a claimed timer or a sleep in application code.

Infrastructure errors use Ecotone's actual `ErrorHandlerConfiguration`: one initial
failed delivery plus three delayed retries at one second, then DBAL dead-letter
storage. Instant retries are disabled so this budget is explicit. A real database
trigger rejects Job claim updates; native retry headers/rows are persisted, Job
stays queued with zero Attempts and zero domain failures, and the exhausted
payload retains its Job ID. After the fault is removed, the native DeadLetterGateway
replays it and that same Job completes. The suite separately proves executor
failures produce three failed Attempts and a terminal failed Job, with no transport
dead letter. These are distinct outcomes.

Native receipt visibility is explicitly 60,000 ms instead of the lower-level
20-minute default. Job leases are 60 seconds. Tests may advance actual native
`redeliver_after`, `delayed_until` and Job lease deadlines to make recovery bounded;
this is eligibility manipulation, not manufactured acknowledgement. Ordinary
expired-delay eligibility also occurs during the domain-retry tests. Sustained
outages of the entire database, failure of dead-letter storage itself and remote
brokers are not covered by the isolated claim-write fault.

## Normal Tempest persistence and DI

The target is one connection owner, not a Doctrine-only Operations island:

1. `OperationsConnectionConfig.config.php` declares the existing PostgreSQL settings
   as Tempest's DatabaseConfig. No connection opens during configuration loading.
2. `SharedOperationsConnection` lazily constructs one DBAL connection per container/
   process. `OperationsConnectionInitializer` binds Tempest's public Connection
   interface to `TempestDbalConnection` over that owner.
3. `JobRuntimeInitializer` resolves the same owner and any department WorkExecutor.
   Ordinary Integration persistence continues using Tempest Database/Query,
   validation and migrations; the domain-specific advisory/claim SQL is retained.
4. With PR #2326, Tempest DatabaseInitializer honors that injected Connection and
   gets dialect/tag from configuration rather than assuming PDOConnection internals.

Fresh child processes call **Tempest::boot**, the same framework entry used by the
console application. The test binds only a disposable departmental executor. Both
Jobs-first and Database-first resolution orders are exercised. Tempest mutation
and Jobs enqueue share PostgreSQL PID and txid; independent SQL sees neither until
commit. Rollback and a subsequent real unique violation remove both. The manual
container proof from round one remains useful regression coverage but is not the
round-two integration evidence.

### Reconnect and connection lifetime

Session search-path initialization must survive reconnects. The supported
DBAL `wrapperClass` extension now uses `SharedPostgresConnection` to initialize the
private schema whenever a new physical session opens, and to refuse closing an
active transaction. Tempest's adapter has no cached PDO; it resolves through the
same owner. Both adapter reconnect and direct DBAL close/reopen are tested, as are
both active-transaction refusal paths. An additional schema-renaming case avoids
PostgreSQL’s default `$user` search path masking a missing initialization hook. The current proof covers explicit reconnect
between transactions and sharing across fresh consumer processes, not autonomous
healing in the middle of a server/network failure.

This costs a small DBAL connection subclass in addition to the Tempest adapter.
Ecotone's installed reconnect helper itself invokes DBAL methods through
ReflectionMethod; Stashd does not reflect PDO or container internals. A future
Ecotone release could use public native-connection resolution instead. Do not claim
that every optional Ecotone reconnect path or tenant switch has been certified.

### Native ecotone/tempest alternative

The official Tempest module recommends `TempestConnectionReference` to reuse the
configured connection. Its inspected dynamic driver still reflects a private
`pdo` property, and its PSR adapter reflects Tempest container internals. PR #2326
does not remove those assumptions. In particular, the public injected Connection
implementation above intentionally has no `pdo` property. The existing native
bridge therefore cannot wrap it as written.

A minimal reproducer, with `ecotone/tempest` available, is:

```php
$container = \Tempest\Core\Tempest::boot(root: getcwd());
$container->get(\Tempest\Database\Database::class);
$factory = \Ecotone\Tempest\Config\TempestConnectionResolver::resolve(
    \Ecotone\Api\Tempest\TempestConnectionReference::defaultConnection(),
);
$factory->createContext()->getDbalConnection()->executeQuery('SELECT 1');
// Current bridge tries ReflectionProperty($injectedConnection, 'pdo').
```

The cleaner candidate uses supported EcotoneLite production bootstrap and explicit
connection registration, plus normal Tempest initializers. It does not install the
native Tempest adapter. To make that adapter interchangeable, upstream needs a
public connection/factory extension that accepts the already-owned DBAL connection
(or a public Tempest native-handle capability with transaction-owner coordination),
and must stop assuming every Connection implementation owns a private PDO field.
Publishing PR #2326 alone is insufficient. This alternative is source-reviewed;
it is not counted as a passing adapter package integration test.

## Schema, deployment and recovery procedure

`JobRuntime` construction performs no queue DDL. Tempest `integration:init` continues
to migrate domain tables. Explicit **`php tempest job:setup`** provisions the queue
and `ecotone_error_messages` using vendor schema managers, before consumers start.
Using the vendor-provided schema rather than copying it into a handwritten Tempest
migration avoids a second schema definition. This is an explicit provisioning
command, not an automatic per-request schema check. It preserves retained messages.

For this candidate:

```sh
php tempest integration:init
php tempest job:setup
php tempest job:work jobs
```

Supervise the single Job consumer with restart-on-exit and deployment restarts.
There is no `job:work outbox`. Stop old consumers before changing payload contracts;
apply schema/provisioning before starting new ones. Do not mix old Messenger
serialized envelopes with Ecotone payloads. Existing Jobs/Attempts and historical
Messenger tables are retained; migrating accepted old delivery intent is still a
rollout obligation, not silently solved by this spike.

Operator recovery uses the native DeadLetterGateway list/show/replay APIs exposed
by the runtime's messaging system. The test invokes replay from a fresh application
process. A user-facing, authorized operator console/UI is not implemented by this
evaluation. Monitor queued/running Jobs, delayed invitations, failed Attempts and
dead letters separately; an exhausted transport delivery is not a terminal domain
Job failure. Test credentials/fixture names never become deployment defaults.

## Complexity comparison

| Dimension | Messenger baseline | Round-two candidate |
| --- | --- | --- |
| Local Job topology | Outbox relay + destination worker | One native database-channel worker |
| Plumbing removed | Envelopes/stamps, senders/locators, JobServices, transport assembly, lifecycle retry/failure listeners, failed-envelope redispatch | Native attributes, channel/error configuration and recovery gateway |
| Domain policy | Work identity/coalescing, promotion generations, claims, Jobs/Attempts, reconciliation | Same; still Stashd's responsibility |
| Ordinary persistence | Tempest connection separate from Operations DBAL | Tempest and Ecotone share one owner via normal DI |
| New connection integration | No supported shared boundary | SharedOperationsConnection, two initializers, TempestDbalConnection, SharedPostgresConnection and configuration |
| Schema ownership | Tempest domain + Messenger message table | Tempest domain + explicit vendor queue/dead-letter provisioning |
| Recovery | Messenger retries/failure transport and relay replay | Native delayed retries/dead letters; no local forwarding boundary |
| Priority | Interactive destination polled first | Native numeric priority; upstream defect fix required |
| Maintenance | Messenger 8.2 dev API; custom worker assembly | Ecotone 2 beta API, compilation/cache lifecycle, two upstream dependencies and small connection integration |

No dependencies changed this round. Relative to baseline, direct addition remains
`ecotone/dbal:2.0.0-beta.7`; transitive additions remain Ecotone core/enqueue,
queue-interop 0.8.2, enqueue/dsn 0.10.27, laminas-code 4.18.0 and Symfony DI
8.2.x-dev. Service-contracts became dev-main and var-exporter 8.1.10 in round one.
DBAL 4.5.0 remains a direct and transitive dependency. No ORM is introduced.
Baseline Messenger dependencies remain for its preserved drivers/tests; no actual
removal is claimed until that verification/deployment transition is designed.

Native per-subscriber event isolation from round one remains demonstrated but is
not expanded into a new preservation implementation here. Production event
projection policy/PREMIS binding remains separate work. No throughput/RSS benchmark
was run. One process and one queue hop removed are measured topology differences,
not a throughput claim. Connection adapter maintenance increases while transaction
ownership becomes coherent. A line-count reduction alone is not the recommendation.

## Licensing

This proof runs without an Enterprise key. Native DBAL channels, explicit delayed
error configuration, basic handlers/interceptors and dead-letter gateway code are
Apache-2.0 (inherited queue code includes MIT). Framework message dedup remains off;
Jobs guard domain execution. Aggregate Sagas are source-present open-source
facilities but were not evaluated. Orchestrator, high-throughput publishing and
some per-handler retry attributes are Enterprise-gated; none is used here. Package
installation is not permission to use all shipped features. This is implementation
licence inspection, not a legal opinion or a general licence audit.

## Evidence, remaining work and reproducibility

See the retained native negative-control JSON and round-two evidence JSON. Raw
process logs/JUnit/hook events stay in ignored `tmp/ecotone-native-negative/`.
Tests use disposable databases and restricted roles through the existing allocator.
The parent terminates/reaps every child, drops only its generated database/role,
and independently audits removal plus filesystem teardown. No production data is
used. Poll/barrier assertion counts can vary with timing.

```sh
lerd composer install --no-interaction --no-scripts
# Apply only when not already present in this worktree's vendor tree:
git apply --directory=vendor/tempest/framework tools/evaluations/tempest-2326.patch
git apply --directory=vendor/ecotone/dbal tools/evaluations/ecotone-dbal-priority.patch
# Supply the existing private local test-admin descriptor (never production).
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/EcotoneDelivery/NativeDurabilityTest.php tests/Acceptance/Operations/WorkAdmissionTest.php tests/Feature/EcotoneTransactionTest.php
python3 -B tools/evaluations/native-negative.py
lerd composer github:fmt
lerd composer github:lint
lerd composer github:analyse
```

Patches are isolated and applied to this evaluation vendor tree; Composer reinstall
can remove them. The priority negative control deliberately removes its behavior
and requires failure at the **original** OPS-C01 next-effect assertion, then restores
exact bytes. Other controls split admission transactions, drop native intent,
permit completed reclaim, repeat the independent effect, ignore generations, drop
busy recovery intent and substitute a separate physical Database connection.
Only a specific assertion failure counts; timeouts, setup failures and unrelated
errors do not. Source restoration and a passing baseline/restored run are mandatory.

The native tests establish bounded Operations delivery equivalence, not all Stashd
product acceptance. Pre-existing Messenger-specific drivers are retained and can
still fail against the replacement; those are documented compatibility tasks,
not durability verdicts. Any full-suite result below must be interpreted by its
specific failing contracts rather than summarized as a green migration.

### Recorded round-two results

| Check | Result |
| --- | --- |
| Native OPS-C02-E | 10 passed, including explicit setup/teardown resource audits |
| Additional non-default schema reconnect case | 1 passed, 36 assertions after strengthening the bootstrap test |
| Native + unchanged OPS-C01 + original transaction/event proof | **15 passed, 626 assertions, 52.81 seconds** in the combined focused run |
| Deliberate negative controls | **8 detected**, baseline and restored native suites both 10 passed |
| Classifier self-checks | Correct assertion accepted; infrastructure exit, wrong location, error and hook failure rejected |
| Format / lint | Passed |
| Static analysis | No errors; 3 existing Records & Archives help messages |
| Full suite, 8 processes | Exit 2: **453 passed, 17 failed, 33 incomplete**, 24,224 assertions, 116.74 seconds |

The 17 retained failures are 7 Integration Jobs tests, 2 WorkAdmissionAtomicity,
1 WorkExecutionContext, and 7 original OPS-C02 cases. Their fixtures still provision
only Messenger tables; assertions/commands also explicitly require Messenger
transport types, envelope rows, visibility columns or the removed relay. They were
not weakened or skipped. The native suite covers corresponding durability outcomes;
legacy driver/provisioning compatibility remains visible work. No unrelated domain
or preservation assertion failed. The 33 incomplete preservation contracts remain
pre-existing product gaps. Focused polling assertion counts vary with timing.

The canonical wrapper was used for all tests. QA components were run separately
rather than using the mutating composer qa chain, preserving protected assertions
and respecting the repository's canonical runtime wrapper.

### Remaining blockers classified

- **Upstream defects/release dependencies:** Tempest must honor injected connections
  in a release; Ecotone DBAL must propagate native priority. Native Tempest adapter
  requires a public extension if selected instead of explicit bootstrap.
- **Unfinished evaluation/rollout:** existing envelope migration, old-version message
  compatibility, supervision configuration, operational UI/authorization, automatic
  network-loss recovery, cache warmup/invalidation and production resource benchmarks.
- **Domain obligations regardless of transport:** department-owned unmet demand and
  reconciliation, resource policy, external-effect idempotency/fencing, long-running
  lease renewal/cancellation, fairness and preservation crash safety.

No inherent need for Messenger's local relay emerged. Ecotone meets these bounded
native guarantees with less delivery infrastructure and a coherent persistence
boundary, at the cost of framework connection integration and release dependencies.
We should discard unnecessary relay machinery once those dependencies and rollout
requirements are resolved, rather than preserve its transport assumptions.

## If Ecotone had been selected first

We would not have built the local outbox relay, extra local destination hop,
Messenger envelope/stamp routing, JobServices locator, lifecycle listener assembly
or failed-envelope redispatch method. A native event bus could also supply durable
subscriber delivery without inventing commit-aware Tempest middleware.

We would still need Work identities, concurrent coalescing, Refresh/Complete modes,
queued promotion policy, Jobs/Attempts, generation checks, leases, resource policy,
departmental reconciliation, client-safe event policy and external-effect guards.
We would also need the shared framework connection integration. Vault/OCFL custody,
fixity, authoritative commit and recovery remain independent preservation work.

## Sources

- [Tempest PR #2326](https://github.com/tempestphp/tempest-framework/pull/2326) and
  [issue #2325](https://github.com/tempestphp/tempest-framework/issues/2325).
- [Ecotone source](https://github.com/ecotoneframework/ecotone-dev) and installed
  DBAL/core/enqueue 2.0.0-beta.7 source for ack, visibility, retry and licence claims.
- [Official Tempest connection guidance](https://docs.ecotone.tech/modules/tempest/database-connection-dbal-module)
  and [Tempest workers](https://docs.ecotone.tech/modules/tempest/asynchronous-tempest).
- [Error channels/dead letters](https://docs.ecotone.tech/modelling/recovering-tracing-and-monitoring/resiliency/error-channel-and-dead-letter)
  and [final failure strategies](https://docs.ecotone.tech/modelling/recovering-tracing-and-monitoring/resiliency/final-failure-strategy).
- [Ecotone AI integration](https://docs.ecotone.tech/other/ai-integration) for skills
  and documentation MCP; guidance was checked against installed APIs and real tests.

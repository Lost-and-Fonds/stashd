# Operations C02/C03 verification — 2026-11-03

This supersedes recovery claims in the earlier implementation/API checkpoints.
The starting point was PR #83 acceptance commit `ab5f417`. Verification uses the
locked Messenger `8.2.x-dev` (`840deb2`) and Durable `dev-main` (`f557f73`), with
Tempest's pinned injected-connections implementation (`5e56e4`).

## Contract and harness corrections

The original three files ran 13 scenarios and 90 assertions. One failed because
`Assignment::get()` does not exist: `Worker::status()` is the public observation
boundary. Eleven scenarios also reported external Lerd PHP 8.5 collector
deprecations. Disabling debug capture removed that environmental interference;
no framework/vendor source was patched and no assertion was suppressed.

The revised suite has **14 passing scenarios, 109 assertions**. It exercises
normal Tempest discovery, real Worker admission, Messenger outbox delivery,
Durable execution, PostgreSQL session ownership and independent persisted effects.
No Stashd component is mocked. The direct publication tests supplement the public
Worker/process cases; they do not claim to prove workflow dispatch themselves.

Changes to the tests and fixture:

- Use `status()` and verify both retained observation values, rejected promotion
  rollback and successful execution after terminal re-admission.
- Restrict the historical Operations fixture to `WorkAdmissionTest.php`; the new
  production-backed contracts no longer allocate or dispose a historical driver.
- Publish handler barrier receipts with an atomic rename, bound new child
  processes to 30 seconds, wait for confirmed backend termination and confirm the
  healthy owner is still alive during the exclusion assertion.
- Hold the real admission lock until PostgreSQL reports all four child admissions
  waiting on it. Starting four processes together alone did not prove contention.
- Describe direct token clearing as revocation, not a fabricated successor.
- Exercise custody adoption with unchanged and changed bytes. Successful adoption
  alone did not establish that a successor actually reverified the bytes.
- Clean only existing process-barrier files in the affected feature tests, avoiding
  missing-file warnings and retaining cleanup of interrupted atomic writes.
- Make the promoted test delivery unambiguously due: PostgreSQL `NOW()` includes
  fractional seconds, whereas Messenger formats its due-time parameter to whole
  seconds. Setting the row one second into the past avoids a boundary-dependent
  false failure without weakening the future-delivery assertion.

Feature tests intentionally retain supplementary ORM/typed-identity assertions,
all-handoff fencing and Durable retry-exhaustion coverage. Historical delivery
contracts remain a separate baseline and are not counted as new C02/C03 proof.

## Production change and recovery removal

No new acceptance failure required changing session fencing or execution authority.
Review of the recovery caller found a real scheduling defect: priority promotion
rebuilt never-started invitations from the journal, bypassing an existing native
queue delay. The regression test observed an effect before the delayed message
was due when run against the original implementation.

Promotion now changes only the queue of existing unclaimed routine deliveries,
inside admission's transaction. It keeps the same message ID, body, headers,
creation time and due time. A conditional update leaves a concurrently claimed
message with its consumer. Conflicting admission rolls the move back. Existing
queued and post-scheduling execution-order tests remain in place.

`Worker::recover()`, `AssignmentExecution::recover()`, `operations:recover` and
the recovery-only test were deleted. Promotion no longer reads the journal to
create deliveries. Messenger retry/failure listeners and Durable's execution
retry decisions remain native. The recovery-only suppression of initial enqueue
errors was also deleted: a trigger-based test confirms that this installed Durable
version exposes Failed, retains the enqueue error, and runs no handler or effect.
No replacement recovery service was added.

Tempest exception: the installed database documentation and query-builder
implementation were inspected. Promotion uses DBAL only for Messenger-owned
transport rows, alongside Messenger's own envelope decoding through `find()`.
This runtime also runs with explicit DBAL connections outside a Tempest application
boot. Adding Tempest database discovery or a model for the upstream transport
would introduce another owner of its schema. The two small parameterized SQL
statements retain the same transport/admission connection and transaction; the
regression checks ordering, delay preservation and rollback. Revisit if Messenger
exposes an atomic queue-move API. The v1 scan of pending routine deliveries is
explicitly bounded in scope, not a demonstrated large-queue performance guarantee.

## Negative controls

Each change was applied temporarily, tested through `bin/test.sh`, then restored
byte-for-byte. Failures below were the expected invariant violations, not setup
errors. The restored acceptance suite passes.

| Deliberate breakage | Observed failure |
| --- | --- |
| Remove the final ownership check after a protected mutation | One effect persisted after session revocation; expected zero |
| Ignore the current Attempt token | Revoked checkpoint publication did not throw `AuthorityLost` |
| Remove admission's transaction | One Assignment survived outbox rejection; expected zero |
| Bypass Activity journal authority | Stale failure appended failure/retry events, including attempt 2 |
| Restore original recovery-based promotion | Delayed work produced one effect before its due time; expected zero |
| Suppress initial enqueue errors again | Assignment stayed Queued instead of exposing Durable's Failed outcome |

## Accepted v1 limits and unproven guarantees

A deterministic initial Activity enqueue rejection now follows the native Durable
terminal failure outcome. No bespoke repair or replay API is provided. A process
crash after journalling but before sending can still strand work; that window and
automatic recovery from it are unproven. Promotion cannot manufacture a missing
delivery.

Process takeover tests explicitly copy retained native envelopes. They prove
safe redelivery and rejection of stale writers, not automatic queue liveness,
failed-transport replay or recovery within a deadline. Session authority has no
TTL/renewal contract. Filesystem/remote effects are not made exactly-once by the
SQL fence. No fairness, starvation, distributed-network-failure or large-queue
performance guarantee is claimed.

## QA classification

The first complete `lerd composer qa` run found one outdated integration test:
`ConfigurationTest.php` expected eight migrations despite the already committed
`UpgradeCheckpoints` migration bringing the ledger to nine. Only that expected
count changed. The isolated adoption test then passed **1 test, 34 assertions**,
including repeated initialization and preservation of identities and constraints.
This was a test defect, not evidence of a migration or Operations defect.

Mago's early-continue autofix conflicted with its unsafe-finally rule in the new
process cleanup. The test now uses the existing feature-suite `array_filter`
cleanup pattern; final lint is clean without further autofixes.

The final affected feature/integration selection passed **50 tests, 438 assertions**
through `bin/test.sh`, covering OperationsDepartment, DurableSpike, shared Model,
existing WorkAdmission and WorkAdmissionAtomicity contracts. The revised C02/C03
suite passed **14 tests, 109 assertions** in the same selection: **64 tests,
547 assertions** overall, with no errors, warnings or incomplete tests. The
Operations feature file accounts for **23 tests, 182 assertions**.

Reproduce the focused result from this Core checkout with:

```sh
STASHD_TEST_PROCESSES=4 ./bin/test.sh tests --filter='Department(Delivery|Fencing|Concurrency)ContractTest|OperationsDepartmentTest|DurableSpikeTest|ModelTest|WorkAdmissionTest|WorkAdmissionAtomicityTest'
lerd composer qa
```

The first command uses the repository's canonical test wrapper. The second is the
requested canonical QA script in the configured Lerd environment; it includes
Mago formatting, lint, analysis and the complete suite with eight processes.

Final canonical QA exited **0**: **556 passed, 33 incomplete, 24,499 assertions**
in 168.66 seconds. There were no failures. Formatting and lint are clean; analysis
has no errors or warnings and retains four existing advisory messages in
`ItemArchive`, `PreservationTransaction` and `JobRegistry`. The 33 incomplete
Records & Archives contracts concern unimplemented archive/projection, metadata,
replication, fault-injection and provenance behavior. They are outside these
Operations contracts and are not presented as passing guarantees.

The final QA run followed all six negative controls after their changes were
restored. Source diffs confirmed no intentional breakage remained. Debug capture
was restored to its original enabled state after verification; the external Lerd
collector's PHP 8.5 deprecation remains an environment limitation when capture is on.

## Shared Operations models — follow-up

Assignment, Demand and Attempt now inherit identity, typed lookup and persistence
fields from `App\Persistence\Model`. Existing constructor arguments and the
Worker submission/status JSON remain unchanged. Assignment and Demand permit
instance insertion only; instance updates/deletes are forbidden. Attempt instance
writes are all forbidden: its creation precedes the new execution context and
must stay inside PublicationAuthority's live-session checks and demand row lock.
Service-owned conditional and multi-row queries remain explicit and now maintain
`updated_at`; protected transaction boundaries are unchanged.

Assignment has no state calculation. Only `Worker::status()` populates its virtual
state. Raw ORM reads expose persisted facts and leave state unset; callers needing
operational state must use Worker. No extra model machinery was needed.

The fresh Operations schema includes non-null inherited timestamps on all three
tables. Existing development schemas need a reset; this change supplies no upgrade
migration for previously installed tables. Lifecycle timestamps such as
`started_at` and `ended_at` remain distinct from model persistence timestamps.

Follow-up verification: the focused selection passed **65 tests, 574 assertions**,
including revised C02/C03 (**15 tests, 135 assertions**) and Operations feature
coverage (**23 tests, 183 assertions**). The new model contract first failed
against the previous non-Model Assignment. Two temporary negative controls then
failed at the expected assertions: omitting the final publication fence leaked
one effect, and omitting the terminal Assignment timestamp update retained an
outdated `updated_at`. Both changes were restored before full QA.

Follow-up canonical `lerd composer qa` exited **0**: **557 passed, 33 existing
archive contracts incomplete, 24,574 assertions**, in 235.43 seconds. Formatting
and lint are clean; analysis retains the same four existing advisory messages.
There were no remaining test failures. The earlier lint suggestions in the new
test were corrected to first-class callable syntax before this final run.

## Integration with current dev

PR #83 targets `dev` and deliberately subtracts the Ecotone replacement merged
there by PR #80. Reconciliation keeps the validated Messenger + Durable runtime,
its locked dependencies and historical Messenger baseline fixtures. Ecotone-only
runtime/configuration, installation probes and framework-specific delivery tests
are removed with that implementation.

The independent OPS-C03 contracts, their disposal tests and the POSIX/container
routing fix in `bin/test.sh` are retained from current dev. The nine older
lease/renewal scenarios still have no installed adapter and remain explicitly
incomplete; they are not replaced with passing session-ownership assertions.
The revised session-based C02/C03 suite remains the production-backed evidence.
The test-wrapper regression checks pass (3 tests).

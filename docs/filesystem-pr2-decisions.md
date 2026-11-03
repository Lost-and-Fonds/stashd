# PR 2: bounded directory acquisition

PR 2 extends the merged PR 1 facilities. Discovery still uses its existing
100-item batches, Catalogue retains existing Item/source identities, acquisition
uses the existing preservation transaction engine, and Broadcast freezes exact
canonical versions and supplies every byte from the Vault. No Catalogue entity,
queue, scheduler, generic repository or recovery manager is added.

## Public boundary and the Operations API

`input:acquire <run>` is a bounded refill command. It admits individual
`AcquireFile(runId, sourceId)` Jobs through ordinary `Worker::do()` and returns
`first_assignment_id` plus the current progress snapshot. The identifier names
the current Assignment of the lexicographically first admitted source. It may
already be completed, and a terminal retry can replace that source's reference.
It does not identify newly admitted work from this particular refill.
`assignment_id` is retained as a compatibility alias with the same semantics,
preserving PR 1's single-file coalescing and post-commit recovery seam. It is not a synthetic run-wide Job or evidence that all files
have completed. For an empty finished run it is null: there is no file Job.

`input:admission <run> [after]` is the read-only run-level view. It reports total,
admitted, settled and outstanding sources, the inclusive last-admitted cursor,
and at most 100 existing attempt records with a `next` source cursor. Settled
means the referenced Operations Assignment completed successfully; independent
OCFL and Microtext checks remain necessary to establish preserved/output bytes.
`outstanding` means admitted but unresolved work, including terminal failures;
it is not a count of queued/running Jobs and must not be labelled “in flight”.
The separate `failed` count identifies currently referenced terminal failures via
`Worker::status()`, including Durable journal failures not yet reconciled into
an Assignment's SQL error field. A positive failed count gives overall status
`failed`; a later refill retries those sources. Read-only inspection does not
reconcile, retry or mutate the references. Totals share one SQL snapshot; failure
projection observes its bounded unresolved set afterward through the existing
Operations API, so this is not an atomic cross-store health snapshot.

After draining workers, call `input:acquire` again while admission is incomplete.
There is deliberately no background refill scheduler. This is the concrete API
tradeoff for review: `Worker::do()` joins equivalent **active** work and can
create another Assignment after completion; it does not provide a permanent
idempotency key or a run-wide waiting primitive. A persisted per-source pointer
and explicit refill avoid hiding those semantics behind another orchestration
abstraction. Existing serialized `AcquireInput` requests retain their handler.

## Two actual commit boundaries

The installed Tempest transaction manager, `ApplicationConnection` adapter,
DBAL nesting and `Worker::do()` were inspected before implementing admission.
Tempest and Worker use the same DBAL application session; nested transactions
are savepoints, not an independent PDO commit. Durable's journal is separate.

For each source, an outer application transaction calls `Worker::do()` and saves
its Assignment UUID on the existing acquisition attempt. The Demand, native
Messenger outbox and pointer commit together. Only afterward does another SQL
statement advance `discovery_runs.admission_cursor`. If admission fails, neither
the pointer nor its outbox survives, and the cursor stays behind. If the process
dies at the cursor write, the committed pointer survives. Replay reuses it even
if the file Assignment already completed; it never calls `do()` again merely
because the cursor lagged. Failed terminal Assignments can get another execution
while keeping the original preservation transaction identity.

A PostgreSQL session lock serializes refill calls for one run. It is distinct
from the existing Input lock used by actual acquisition, so admitting Jobs does
not force healthy file workers to collide with the refill command. Capacity is
computed from actual referenced Assignment state before admitting more files;
each call processes at most one 100-source page. No production retry or delivery
policy changes. PostgreSQL releases the run lock on process exit.

## Catalogue and preservation

Discovery catalogues all saved observations in 100-record pages. Identity
allocation/restoration and discovered membership retain PR 1's Assignment
context fence. Membership commits one page at a time; earlier authorized pages
survive a crash, and replay fills only missing membership without new identities.

Each file handler enters the existing Input lock, reads its exact saved
observation, reuses its persisted transaction UUID, and invokes the same staging,
unchanged-content check and canonical commit/recovery logic as PR 1. Canonical
source reassociation runs once during discovery, not once for every file Job.
This retains its existing conflict/damaged-history checks. The legacy diagnostic
run-wide preservation path remains available.

The existing Broadcast publisher already verifies Vault streams and matching
destination bytes, then skips the rename when output matches. PR 2 proves this
across page boundaries and unchanged rescans; it does not introduce a second
publication algorithm or hardlinks.

## Tempest facilities and narrow SQL exceptions

Models, queries, migrations, DI, mapping and commands use installed Tempest
facilities. The additive tracked migration extends existing run/attempt rows;
there is no admission entity table. Two short PostgreSQL ALTER statements retain
the existing composite-key attempt table and add the cursor and nullable unique
Assignment foreign key. Joined aggregate counts use PostgreSQL `FILTER` to return
one consistent progress snapshot. The session advisory lock follows the existing
Integration locking pattern and must span the two independent commits; a
transaction-scoped lock would end before the cursor boundary. Revisit those
small SQL exceptions when the installed framework can express those operations.

## Acceptance review and limits

The three PHP files in #88 were formatted with Mago before implementation and
passed syntax checks. Against merged PR 1 the three application contracts failed
at the real single-file restriction; the missing-page external oracle control
passed. That distinguishes missing behaviour from CI's initial formatting failure.

The proposed CLI wording is clarified to identify an actual per-file receipt,
not an extra run-wide execution Assignment. The cursor SIGKILL test additionally
requires a committed per-file Demand at its barrier. The harness now explicitly
walks discovery and admission pages, clears PHP's stat cache before inode checks,
and uses an explicit first OCFL manifest path instead of a one-iteration loop.
No original behavioural requirement or physical-byte assertion was removed.

The admission fault trigger and cursor advisory barrier act only on disposable
fixture databases. The 100/101/1,000 suite remains outside routine `phpunit.xml`
suites. It verifies per-source Demands, the 100-outstanding guard, unchanged v1
histories, exact pins/digests, source deletion and repeated output inode identity.
The 10,000-file full application run remains PR 5.

Remaining limits: file acquisition within one Input still uses its existing
exclusive lock; this PR establishes bounded admission, not parallel preservation
throughput. Canonical provenance reassociation still inspects existing Vault
metadata/history. Broadcast may stage verified bytes before skipping an already
matching output, and publication remains atomic per file rather than per tree.
No hostile-writer, power-loss, rename-inference or broader interruption guarantee
is added here. PR 3 is not started.

## Verification actually performed

All application tests ran through the canonical `./bin/test.sh` in the intended
Lerd PHP 8.5 environment with generated disposable PostgreSQL databases.

- Before implementation: **3 application contracts failed** at the existing
  single-file restriction; the independent missing-page control passed.
- Combined PR 2 small suite and PR 1 round-trip/fencing/upgrade regressions:
  **10 passed, 2,015 assertions** (before the additional empty-run case).
- Opt-in full stress suite: **3 passed, 125,588 assertions**, covering 100, 101
  and 1,000 files. Observed case durations were 68.31 s, 75.27 s and 645.51 s;
  total 789.17 s. Each case includes unchanged reacquisition, independent OCFL
  checks, source removal, two Broadcasts and external verification. These local
  elapsed times are evidence of execution, not throughput targets or benchmarks.
- Additional 101-file case with two competing refill processes: **1 passed,
  10,754 assertions**, 162.63 s while full QA was also running. It requires
  exactly 100 outstanding Jobs before workers drain, then the complete round trip.
- Deliberately advancing the cursor before failed Worker admission made the
  existing fault contract fail with: “The persisted cursor skipped a source
  without a committed per-file Job admission.” The bad change was restored;
  the same test then passed (**300 assertions**).
- Final full `lerd composer qa`: **582 passed, 42 existing incomplete contracts,
  zero failures**, exit 0, **26,709 assertions**, 213.25 s. Formatting/lint passed;
  analysis reported only the four existing informational notices.
- The stress workflow YAML parsed successfully. Its separate directory job uses
  the existing CI PHP/PostgreSQL fixture setup and runs this opt-in suite only
  after merge, on release or manual dispatch. It has not been remotely dispatched
  as part of this draft. Routine PR CI still excludes `tests/Stress`.

The 10,000-file workflow step remains the independent fixture self-check; a real
10,000-Item application run remains PR 5. No merge or PR 3 work is included.

## Inspection API review follow-up

The review's receipt concern is handled with explicit naming, rather than a new
coordinator Job: `first_assignment_id` names the first admitted source's current
Assignment; `assignment_id` is its compatibility alias. A completed refill can
return that completed representative without claiming it admitted new work.

Unresolved totals now include a bounded UUID set from the same SQL snapshot.
The `failed` projection uses the existing read-only Worker status API so it also
sees terminal Durable failures before SQL admission reconciliation. It does not
retry, reconcile or change capacity. Consumers must label `outstanding` as
unresolved work, and display failures separately rather than calling it in-flight.

Both new public-CLI regression tests failed before the fix. Afterward, the
focused inspection/PR 1/PR 2 run passed **9 tests, 1,954 assertions**. The separate
101-file competing-refill round trip passed **10,799 assertions**, and final
`lerd composer qa` passed **584 tests, 42 existing incomplete contracts, zero
failures**, exit 0 (**27,027 assertions**). Formatting/lint passed; analysis
retained only the four existing informational notices. The 1,000-file proof is
not repeated for this inspection-only change; its original evidence remains
above. Admission, cursor ordering, preservation and delivery policy are unchanged.

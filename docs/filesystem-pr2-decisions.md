# PR 2: bounded directory acquisition

PR 2 extends the merged PR 1 facilities. Discovery still uses its existing
100-item batches, Catalogue retains existing Item/source identities, acquisition
uses the existing preservation transaction engine, and Broadcast freezes exact
canonical versions and supplies every byte from the Vault. No Catalogue entity,
queue, scheduler, generic repository or recovery manager is added.

## Public boundary and the Operations API

`input:acquire <run>` is a bounded refill command. It admits individual
`AcquireFile(runId, sourceId)` Jobs through ordinary `Worker::do()` and returns
`assignment_id` plus the current progress snapshot. The identifier is a real
per-file Assignment, retaining PR 1's single-file coalescing and post-commit
recovery seam. It is not a synthetic run-wide Job or evidence that all files
have completed. For an empty finished run it is null: there is no file Job.

`input:admission <run> [after]` is the read-only run-level view. It reports total,
admitted, settled and outstanding sources, the inclusive last-admitted cursor,
and at most 100 existing attempt records with a `next` source cursor. Settled
means the referenced Operations Assignment completed successfully; independent
OCFL and Microtext checks remain necessary to establish preserved/output bytes.
Failed Assignments remain unsettled and are retried on the next refill.

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

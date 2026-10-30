# Work admission: bounded Operations slice

`Jobs::request(Work $work, string $priority = 'routine'): string` returns the durable
Job ID for a canonical `(type, scope, mode)` identity. Identifiers are stable,
case-sensitive ASCII strings of at most 200 bytes. Observation is a nonempty input
snapshot of at most 1024 characters, not part of identity. A repeat while active
joins the existing Job and retains its original observation. Callers must provide
canonical identifiers; this API does not normalize aliases.

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
container). It must reject unsupported Work and throw on unsuccessful execution.
A missing executor fails Work rather than inventing completion. The test-only
probe implements this port and writes one durable effect per call through a
separate connection, with no uniqueness constraint that could hide duplicates.
Real StashInput-to-Work mapping remains separate work.

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

## Evidence and remaining limits

PR #75's six test-only files are imported unchanged from
`99b8ae6693744bd4b1ec29ad4d044323eef5d9c7`. `phpunit.xml` installs the real driver;
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

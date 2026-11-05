# Filesystem preservation experiment — Checkpoint B

Checkpoint A (`cf5f552`) is approved. This is the first **preservation-only** slice,
stopping for review before rescan, source removal, publication, repeat publication
or corruption-rejection phases. Broadcast remains one serial destination-locked Job.
Existing round-trip/recovery contracts are regressions, not a Checkpoint C claim.

## Run it

Use the existing [development fixture prerequisites](filesystem-stress-cli.md#prerequisites-and-safety):
Composer dev dependencies, stable `SIGNING_KEY`, Linux/POSIX, Python 3, and the
private PostgreSQL fixture administrator file. No existing source, Vault or working
database can be selected. Only randomly generated, identity-checked fixtures are used.

```sh
mkdir -p tmp/preservation-reports
php tempest stress:filesystem-preserve --count=100 --workers=1 \
  --report-dir=tmp/preservation-reports
php tempest stress:filesystem-preserve --count=100 --workers=4 \
  --report-dir=tmp/preservation-reports --no-interaction --json
```

On Lerd, run these through the site's console execution environment. Interactive
mode keeps the preview's configuration/review/confirmation, collapsing tasks,
live counts, compact completion labels and final result table. At 40 columns,
counters come first rather than disappearing after a long activity label.
Generation and external source checking report actual completed files through
optional JSON progress lines; existing generator/verifier defaults are unchanged.
Discovery counts committed observations, and acquisition counts successful durable
Assignments. Those counts may jump when a batch commits or between samples; they
are never interpolated. A distinct verification sub-label advances only after each
Item's exact pins, OCFL inventories/native digests and original bytes pass.

Only tasks **01, 02, 03 and 09** execute here. Tasks 04–08 are explicitly **not run**,
not simulated or marked successful. The unchanged nine-task preview remains the
visual reference. The older `stress:filesystem` command and its uncommitted local
rewrite are preserved; the deliberately named preservation command avoids presenting
this slice as the completed round trip. No permanent API uses a PR/checkpoint number.

The experiment runner has no terminal dependency. Its presentation callback wraps
the same stage bodies in `FilesystemStressTask`; unattended/JSON mode invokes those
bodies directly. JSON stdout is one undecorated final report; stage messages go to
stderr. Presentation coalesces unchanged counts and gives actual subphase changes
two renderer frames (200 ms) to become visible. This is display flushing, not fake
work or estimated progress. Timings include that cost.

## Admission and execution authority

`AcquisitionAdmission::fill()` no longer computes `100 - outstanding` or stops
admitting when consumers fall behind. One call walks **all** saved attempt pages
and admits every eligible source. The 100-row sizes limit query memory only.
There is no replacement ceiling, policy or configuration. The previous unbounded
`json_agg` of unresolved UUIDs is also removed: failure inspection and terminal
retry checks walk source-cursor pages, including failures beyond the first page.

The per-run admission lock is retained. Each Job/outbox write and source Assignment
pointer still share the existing Tempest/DBAL transaction; only after that commit
does the inclusive discovery cursor advance. A crash between those boundaries
replays the saved pointer without duplicating its Demand. Another producer cannot
skip unfinished admission. Existing admitted/completed sources are not readmitted;
terminal retries replace only the current failed Assignment pointer. Totals use one
SQL snapshot; paged failure checks can observe later transitions and are not an
atomic cross-store health snapshot.

The old acquisition implementation held an exclusive lock on the entire Input
while copying/preserving each file. Four ready workers would therefore serialize.
Per-file acquisition now holds:

- a **shared Input advisory lock**, excluding discovery, synchronous preservation
  and source-identity reassociation;
- an **exclusive source advisory lock**, scoped to Input + source **across runs**,
  so the same source cannot race its identity, attempt or archival revisions.

Distinct sources can execute together. Lock contention fails the handler rather
than ignoring exclusion; normal Operations retry/fencing remains authoritative.
PostgreSQL releases session locks on process death. The existing source/custody
checks, transaction identities, Item/Asset locks, publication authority and final
Vault publication lock remain unchanged. Narrowing this Input lock is not a
redesign of Operations or a removal of Broadcast's destination lock.

Discovery completes with exhaustive coverage before the producer starts. The same
continuous consumer PIDs then survive into acquisition. `input:acquire` runs as a
separate finite Tempest command while consumers independently process the admitted
Jobs. Empty queues do not finish the experiment. Completion requires the expected
total, all current Assignments successfully settled, no unresolved/failed sources,
the exact per-file Demand/source set, satisfied Demands and saved preservation
Results. **Those checks still do not prove preservation.** After stopping workers,
the independent physical witness must verify the entire expected membership and
every exact Item/Asset version and byte digest before task 03 is labelled preserved.

`PreservationWitness` contains the extracted membership, per-file Demand and
physical Item/Asset/OCFL checks. Historical `Pr2Directory` callers delegate to it,
retaining all assertions and negative controls. Source generation, mutation,
publication and recovery helpers have not been bulk-migrated. New finite commands
use inspected Tempest `PendingProcess`/`GenericProcessExecutor`; application locks
stay in the existing PostgreSQL-specific store through Tempest `Query`. Independent
PDO/SQL reads and fault controls belong to the identity-checked fixture witness,
not a second application persistence or supervision framework.

## Lifecycle corrections and failure accounting

- `OperationsWorkerStartupException::getPrevious()` retains the original startup
  error; `cleanupFailure` separately retains a failed `stop()`, including its cause.
  A terminal signal racing an already exited child is not reported as orphaning
  when subsequent termination/reaping is confirmed.
- Renderer shutdown polls `waitpid(WNOHANG)` for up to one second after SIGHUP,
  then sends SIGKILL and polls for one further second. It never blocks indefinitely.
  Unexpected renderer SIGKILL remains a task failure; forced SIGKILL is accepted
  only when the owner requested escalation. Ignored HUP and SIGSTOP are tested.
- Owner SIGINT/SIGTERM only set cancellation state. Ordinary failures unwind the
  finite producer/CLI child, then signal, terminate and reap all owned consumers.
  The runner independently checks `/proc` absence before database disposal, checks
  the fixture ownership marker, and independently confirms database **and role**
  absence before declaring cleanup complete. Storage absence is checked too.
- The experiment fails fast on worker loss or a recorded failed acquisition
  Attempt; it does not silently restart workers or turn a partial run into success.
  Native retry state is retained in the report, but recovery is not demonstrated
  by this slice. Job counts observed before cancellation and counts at shutdown
  are separate: graceful shutdown may finish additional work. Neither advances the
  independent verification count. Reports retain failure phase/type, redacted
  diagnostic, attempt histories and cleanup outcomes, even when verification never
  begins. Failure to confirm worker cleanup retains the disposable resources.

The Prompts dependency remains exactly **0.3.26**, with the reviewed reference and
four source-hash guards from A. No dependency update or new package is needed.
Owner SIGKILL, machine loss, disk exhaustion or an unkillable kernel task still
cannot guarantee cleanup. A non-confirmed resource must be attributed before manual
removal. The adapter's inherited-resource/expected-signal assumptions remain in
the [lifecycle decision](filesystem-stress-worker-lifecycle.md).

## Executed demonstrations

These are real CLI runs, including configuration/confirmation and controlling PTYs,
against a fresh install of the published lock (135 packages, Mago 1.57.0, Pest
5.3.2, PHPUnit 13.4.2). Neither used a sleep/barrier to manufacture worker overlap.
Both generated the same Microtext manifest:
`107616c4f740c21a81baebc7d93e562d2cbbb581f43595e0d83fd379fae70c4b`.

| Observation | 100 files / 1 worker | 100 files / 4 workers |
| --- | ---: | ---: |
| Terminal width | 80 | 40 |
| Admitted / completed Jobs | 100 / 100 | 100 / 100 |
| Independently checked Items / Assets | 100 / 100 | 100 / 100 |
| Sampled simultaneous acquiring workers | 1 | **3** |
| Sampled max unresolved Assignments | 100 | 78 |
| Completed Jobs observed at producer return | 0 | 29 |
| Admission command duration | 1.479 s | 3.596 s |
| Whole experiment duration | 19.365 s | 23.251 s |
| Sampled owner + consumer RSS peak | 170812 KiB | 430536 KiB |
| Database size before disposal | 10640407 bytes | 10599447 bytes |
| Workers, database, role and storage gone | confirmed | confirmed |

**No speedup is claimed.** These are uncontrolled instrumented timings; four
consumers were slower in this run. RSS sums separate resident sets, may double-count
shared pages, excludes renderer/finite-command children and is not PSS. Queue width
and acquiring-worker maxima are samples, not continuous peaks.

The overlap witness joins granted exclusive source locks to running acquisition
Attempts, then PostgreSQL session `application_name` to the real consumer's OS PID.
A tiny worker bootstrap sets `PGAPPNAME=stashd-filesystem-worker:<pid>` before
entering the unchanged Tempest `operations:work` command. It adds no work or delays.
Authority and application sessions are distinct, so the witness records both DB
backend IDs rather than falsely joining them as one connection. Workers merely
waiting for a source/Input lock do not count.

In the four-consumer run, OS PIDs **39027, 39038 and 39058** simultaneously held
different source locks with distinct running Assignments/Attempts (pool also
contained **39048**). A separate sample records an acquisition while the producer
command was alive. In the one-consumer run, PID **38724** was the only holder;
admission finished before its first completed Job was sampled. Thus availability,
actual overlapping execution, completed Jobs and verified preservation remain
separate facts.

## Tests and QA

| Executed group | Result |
| --- | --- |
| Pool, renderer deadline, Prompts source guards and CLI boundaries | 18 tests / 52 assertions |
| Real slice, partial cancellation/worker death, real handler failure, CLI 40/80-column PTYs, Ctrl+C/SIGTERM and JSON | 10 tests / 1155 assertions |
| Admission + existing directory/cursor/rollback/negative witnesses | 7 tests / 2291 assertions |
| Operations delivery/concurrency/fencing, Jobs and admission atomicity | 24 tests / 391 assertions |
| Original A lifecycle matrix | 9 tests / 208 assertions |
| Existing recovery contracts | 10 tests / 2381 assertions |
| Existing mutation contracts | 2 tests / 2100 assertions |
| Required 100/1 and 100/4 CLI demonstrations | 2 tests / 49 assertions |
| Independent Python fixture regressions, including progress/corruption | 16 tests passed |

The admission control admits **137 outstanding Assignments** with consumers absent,
races two producers without duplicate Demands, then retries 137 terminal-failure
controls across page boundaries. Existing real outbox rejection and SIGKILL between
admission and cursor advancement still pass. A separate real lock probe confirms
that another source can acquire while the same source and discovery remain excluded.

Changed-file formatting/lint and scoped application analysis pass using locked
Mago 1.57.0. **`composer qa` was attempted and remains blocked** by the same two
pre-existing preview `str_repeat` argument errors; its full parallel suite was not
reached. QA autoformat/autofix changes stayed in the disposable source export.
The approved preview and unrelated local command/Composer/lock/report changes are
not overwritten or staged. This is not a green whole-repository QA claim.

Rerun the focused slice through Lerd with an optional pre-created evidence directory:

```sh
STASHD_PRESERVATION_EVIDENCE_DIR="$PWD/tmp/preservation-reports" \
  vendor/bin/pest tests/Acceptance/Integration/FilesystemPreservationExperimentTest.php \
  tests/Acceptance/Integration/FilesystemPreservationCliTest.php
STASHD_PRESERVATION_EVIDENCE_DIR="$PWD/tmp/preservation-reports" \
  vendor/bin/pest tests/Stress/Integration/FilesystemPreservationStressTest.php
```

The 100-file demonstrations live in the opt-in stress suite, not ordinary CI.
10,000 remains opt-in; 1,000/4 and the remaining real round trip await Checkpoint C.

## Retained local evidence

Relative to `/home/hazel/Projects/stashd/core/core-feat-filesystem-stress-cli/`:

- Reports, raw PTYs and JSON stdout: `tmp/checkpoint-b-review/evidence/`.
- Validation logs and clean source export: `tmp/checkpoint-b-review/`.
- Latest 100/1 report: `evidence/100-1-75cbed1f/filesystem-preservation-cfa2ee818ab6a3c8.json`,
  SHA-256 `2988f7a79dd3488ca5e13c54f235ec2117a8b5646da61e71fe72a4869cebd169`.
- Latest 100/4 report: `evidence/100-4-5d1800a7/filesystem-preservation-df85b1badb02a4f3.json`,
  SHA-256 `0e6e691d9e4058342a02b4de4bb6cfdf7ce39b2a7bb80a33aebf5982d49d790d`.

Each report includes code/lock hashes, exact completion receipts, per-file physical
verification references, actual overlap witnesses and pre-teardown attempt history.
Partial/failure reports remain failures with zero independent verification unless
that verification actually occurred. The directory's `SHA256SUMS` indexes retained
evidence/logs; its digest is published with the PR review update. No generated
recording, private configuration, scratch export or broad dependency refresh belongs
in this commit. Implementation stops here for Checkpoint B review.

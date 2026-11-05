# PR 5 — measured 10,000-Item application round trip

This document retains the original PR 5 evidence and historical invocation names.
For the current command, semantic helper/test names, report-directory setting and
compatibility notes, see [Filesystem stress CLI](filesystem-stress-cli.md).

**Status: the genuine pinned 10,000-Item round trip passed, following separate
100- and 1,000-Item passes. Workflow integration and local QA are complete;
architectural review is required before merging.** PRs 1–4 are merged. The 10,000-file
manifest/reference supplied by [#85](https://github.com/Lost-and-Fonds/stashd/pull/85)
remain unchanged. This work deliberately lives in `tests/Stress`, outside
ordinary Pest/PR CI. There are no production code, schema, queue, OCFL or
framework changes.

## Success contract

The same code path must work for 100, 1,000 and 10,000 **independent Items**:

1. Generate the original Microtext source A and external manifest. At 10,000,
   verify the generator's output against the committed
   `tests/fixtures/microtext/reference-v1.json` before any application work.
   Other sizes use the same generator and per-file manifest but are not pinned
   by that reference. Never silently regenerate the reference.
2. Create a real Stash, attach a first-party Filesystem Input, and discover
   through `input:discover` and the actual Operations worker. Inspect all
   bounded discovery and membership pages for unique identities.
3. Refill at most 100 outstanding per-file acquisition Assignments, execute
   actual `operations:work --once --limit=100` batches through Messenger and
   Durable, and require every distinct source to have an admitted Demand.
   Do not replace real workers with one large synthetic handler or 10,000
   concurrent CLI processes.
4. Independently inspect the physical OCFL inventories, sidecars, v1
   Item–Asset pins, native payload digests, original paths and full reference
   bytes. Require exactly 10,000 canonical Items and exactly 10,000 Assets,
   with no extra OCFL Objects. Job state is *not* preservation proof.
5. Rescan/reacquire unchanged A through the same application commands and
   require unchanged Stash membership, Item/Asset identities and versions.
6. Destroy only the disposable source root **before** freezing/publishing the
   requested Broadcast selection. All output must come from verified Vault
   Asset streams; no source-path fallback. Do not delete the Vault's custody
   journal or any evidence needed for crash recovery.
7. Publish to B using a real frozen selection and `broadcast:build`.
   Check every bounded publication-report page and verify B with the
   **external** manifest (and pinned reference at 10,000).
8. Republish the unchanged collection and verify every existing output inode
   is unchanged. After the successful run, deliberately corrupt one output;
   the independent verifier **must fail** even if all Jobs/Reports say complete.
   This is the negative control; it affects only a disposable output root.

The test currently uses
`tests/Stress/Integration/FilesystemRoundTripPr5StressTest.php`.
It reuses PR 1–4 helpers for actual app entry points and independent OCFL
inspection. The scale-specific driver groups worker drains and observes
progress; it does not write archival data or fabricate Results.

## Harness review (2026-11-04)

The initial CI run [41793399880](https://github.com/Lost-and-Fonds/stashd/actions/runs/41793399880)
passed formatting, lint, static analysis and the full routine suite. Local
inspection found an uninitialized publication-report identity set; it is now
initialized before pagination. The shared CLI assertion now includes malformed
JSON output in its failure message rather than reporting an unexplained parser
exception. No response prefixes are stripped and no assertions are relaxed.

`DurableRuntime::drain()` delegates `--once` to Messenger's `stop_when_empty`
and `--limit` to its `StopWorkerOnMessageLimitListener`. The installed listener
counts **messages**, not Items or Assignments. Outbox, workflow and activity
deliveries all consume the budget. A bounded drain may therefore settle fewer
than 100 Items; the harness records worker boots, their elapsed time and actual
settled deltas rather than describing each boot as a 100-Item batch. An empty
worker baseline includes PHP/Tempest/DB/transport setup, not just PHP startup.

Phase timers now exclude the preceding checkpoint's SQL counts and Vault walk;
that observer cost is reported separately as `measurement_seconds`. Acquisition
reports separately time admission commands, workers and read-only CLI progress
inspections. Discovery worker execution is separated from discovery/membership
page verification. Broadcast selection, worker execution and report pagination
are separate intervals. Worker intervals still include fresh-process boot and
application setup: they are not pure handler CPU time.

The independent sampler writes timestamped RSS observations, permitting a peak
for each phase. An interval with no sample reports `null`, not zero. The overall
peak includes the parent acceptance harness and external verification; per-phase
peaks distinguish those from application intervals. The sampler excludes itself
and stops if its supervised parent disappears. Its own CPU cost and shared-page
double counting remain measurement limitations.

Tempest's installed process documentation and `GenericProcessExecutor` /
`InvokedSystemProcess` were inspected. The existing PR 2–4 process-resource helper
is retained to supervise the exact child and keep independent acceptance probes
free of parent application bootstrap, process mocking and output normalization.
This is test-only, not an alternative production process layer; revisit if these
helpers are moved into framework integration tests. Native read-only PostgreSQL
size/catalogue and aggregate probes independently witness actual persisted state;
they do not create application schemas, admit Jobs or replace Tempest persistence.

The first local attempts exposed an **environment/instrumentation failure**:
Lerd's `devtools-collector.php:644` calls `ReflectionProperty::setAccessible()`,
deprecated in PHP 8.5, and prints that warning before a valid `input:discover`
JSON response. No acquisition had executed. Strict JSON validation correctly
rejected it. Failed reports were retained outside the fixture. Measured child
processes use a private, additional PHP ini directory setting
`lerd.devtools_flag=/disabled-for-pr5-measurement`; the normal extension ini files
are still loaded. No global Lerd settings or working databases were changed.
The fixture uses PHP **8.5.12** and PostgreSQL **16.15**, a unique database and
unprivileged role per attempt, and a stable private signing key across workers.
Disposable Vaults live on the PHP container's **OverlayFS** filesystem, not a
dedicated archival volume; `memory.max` is `max` (no container memory cap).
PostgreSQL runs separately and is not included in the PHP process-tree RSS.
Filesystem performance and block amplification must not be extrapolated to a
different storage backend from these observations alone.

## Measurements and what they mean

The separate `Pr5Scale` helper writes one JSON report per count and samples:

- Wall time for fixture generation, discovery, first preservation, unchanged
  rescan, source removal, first Broadcast, repeat Broadcast and negative
  verification. Report *phase durations*, not an invented throughput target.
- PostgreSQL database size and persisted counts for discovery, acquisitions,
  Assignments, Demands, Attempts, Results, reports and Messenger messages at
  phase boundaries. Attempt outcome counts distinguish retry/failure history
  from mere Job completion.
- Sampled `input:admission.outstanding` widths on each refill/worker drain,
  including the largest observed sample. This is **not an instantaneous
  transport queue-depth maximum**. Actual admission remains capped at 100
  by existing product contracts; add a DB-side bound probe if a missed
  transient spike matters to a measured claim.
- Linux `/proc` sampler recording the maximum observed sum of resident
  memory across the PHP test process and its descendants, including worker
  CLI processes. RSS may count shared pages multiple times, and sampling
  can miss shorter-lived peaks. Do not mislabel it unique system memory or PSS.
- End-of-run Vault file count, sum of logical file sizes and allocated blocks,
  including the OCFL metadata and retained custody evidence, not only payload
  bytes. PostgreSQL database-size deltas and Vault bytes are different
  quantities.

These are **instrumented acceptance observations**, not a controlled repeated
performance benchmark. Exception, Messenger-retry, syscall-write and continuous
per-queue observations remain incomplete; the limitations below are not zero
measurements. No new queue, orchestration, persistence or archival mechanism
was needed, and no application optimisation was made.

The JSON report is printed with a `PR5_METRICS` prefix and, when
`STASHD_PR5_REPORT_DIR` names an existing directory, copied there before the
fixture is disposed. Do not add generated reports or source files to Git.
Completed checkpoints are also atomically saved as a separate
`*.partial.json` report with status **incomplete**. This survives fatal errors
that bypass PHP `finally`; it is removed only after the complete report is saved.
An incomplete report is never a successful acceptance result.

## Completed local comparison

All three cases ran separately, in order, through real application commands and
Messenger/Durable workers. The 10,000 case was rerun **from scratch** after fixing
the verifier-only memory failure documented below. The original Python generator,
reference pin and external verifier are unchanged.

| Observation | 100 Items | 1,000 Items | 10,000 Items |
|---|---:|---:|---:|
| Timed phase total, seconds | 118.091 | 755.158 | 4,899.837 |
| Whole Pest wall time, seconds | 119.21 | unavailable after supervisor detachment | 4,927.33 |
| Original Items / total phase seconds (includes two ingestions/publications and checks) | 0.847 | 1.324 | 2.041 |
| First acquisition, seconds / Items per second | 44.117 / 2.267 | 325.550 / 3.072 | 2,190.452 / 4.565 |
| Unchanged acquisition, seconds / Items per second | 41.065 / 2.435 | 314.408 / 3.181 | 1,999.937 / 5.000 |
| Peak observed summed RSS, MiB | 149.1 | 163.0 | 214.4 |
| Final PostgreSQL size / growth, MiB | 11.19 / 3.78 | 24.11 / 16.70 | 150.50 / 143.09 |
| Vault logical / allocated regular-file blocks, MiB | 0.82 / 7.04 | 8.21 / 70.32 | 82.07 / 703.14 |
| Vault regular-file entries | 1,904 | 19,004 | 190,004 |
| Verified immutable OCFL versions (Item + Asset) | 200 | 2,000 | 20,000 |
| Assignments / Demands / completed Attempts / accepted Results, each | 204 | 2,004 | 20,004 |
| Extra Attempts for existing Demands | 0 | 0 | 0 |
| Acquisition worker boots across both ingestions | 12 | 120 | 1,200 |
| Largest sampled acquisition admission width | 100 | 100 | 100 |
| Largest sampled sum of transport messages | 100 | 102 | 102 |

MiB means 1,048,576 bytes. PostgreSQL baseline was **7,765,015 bytes** in each
fresh database; size includes tables, indexes, migrations and database overhead.
Checkpoint SQL and Vault walking are excluded from timed phase intervals and
reported separately. Host/cache/load conditions were not controlled: the later
100-Item recheck took 67.97 seconds without an application change. Do not infer
a speedup, capacity limit, parallel throughput or statistical scaling law from
these individual observations.

### Pinned 10,000-Item result

The full retry passed **1 test / 1,268,774 assertions in 4,927.33 s** (**82.12 min**).
Checkpoint observation cost was **20.892 s**, outside the **4,899.837 s** phase
total; setup, report construction and disposal account for the remaining wall
time. Peak observed RSS: **219,576 KiB**. Final PostgreSQL: **157,809,687 bytes**.
Vault: **86,051,459 logical bytes**, **737,292,288 allocated file-block bytes**.

The retained JSON records every phase's RSS, PostgreSQL size, Vault size and
persisted counts. The following table makes the application/verification
boundaries explicit; throughput is shown only for collection-processing phases.
RSS always includes the acceptance parent, including allocations retained from
previous verification, and descendants—not application-only memory or PostgreSQL.
Short intervals without a sample are `null` in the raw report, never zero.

| 10,000-Item phase | Seconds | Items/s | Peak summed RSS, MiB | PostgreSQL, MiB | Vault logical / allocated file MiB |
|---|---:|---:|---:|---:|---:|
| Generate fixture | 0.494 | — | 75.8 | 7.41 | 0 / 0 |
| Independently verify source/reference | 0.310 | — | 87.6 | 7.41 | 0 / 0 |
| Configure and execute discovery | 102.256 | 97.794 | 150.9 | 30.81 | <0.01 / 0.01 |
| Verify discovery and Catalogue pages | 77.032 | — | 152.1 | 30.86 | <0.01 / 0.01 |
| Empty worker baseline | 0.323 | — | 133.8 | 30.86 | <0.01 / 0.01 |
| Admit, drain and inspect first acquisitions | 2,190.452 | 4.565 | 160.0 | 72.10 | 82.07 / 703.14 |
| Verify canonical Item/Asset v1 and exact pins | 41.265 | — | 156.2 | 71.04 | 82.07 / 703.14 |
| Execute unchanged discovery | 279.980 | 35.717 | 209.5 | 83.62 | 82.07 / 703.14 |
| Admit, drain and inspect unchanged acquisitions | 1,999.937 | 5.000 | 190.0 | 125.95 | 82.07 / 703.14 |
| Verify unchanged membership, inventories and pins | 43.136 | — | 180.6 | 125.95 | 82.07 / 703.14 |
| Remove source A before any Broadcast selection | 0.129 | — | 111.0 | 124.86 | 82.07 / 703.14 |
| Freeze first Broadcast selection | 26.525 | 376.996 | 195.8 | 132.53 | 82.07 / 703.14 |
| Admit/drain/inspect first Broadcast Job | 24.521 | 407.821 | 213.8 | 137.71 | 82.07 / 703.14 |
| Verify first publication report pages | 29.614 | — | 186.4 | 137.73 | 82.07 / 703.14 |
| Independently verify all 10,000 output files | 0.295 | — | 133.6 | 137.73 | 82.07 / 703.14 |
| Freeze repeat Broadcast selection | 26.718 | 374.274 | 194.2 | 145.30 | 82.07 / 703.14 |
| Admit/drain/inspect repeat Broadcast Job | 25.096 | 398.472 | 214.4 | 150.50 | 82.07 / 703.14 |
| Verify repeat publication report pages | 31.206 | — | 186.3 | 150.50 | 82.07 / 703.14 |
| Independently verify 10,000 outputs and unchanged inodes | 0.298 | — | 133.7 | 150.50 | 82.07 / 703.14 |
| Corrupt one output; require external verification failure | 0.251 | — | 133.5 | 150.50 | 82.07 / 703.14 |

There were exactly **10,000 canonical Items and 10,000 Assets**, each with only
**v1**, matching historical inventory sidecars, exact Item-to-Asset pins and
independently hashed payloads. The unchanged scan retained the original
membership and immutable inventory hashes. Source A was physically absent
before the first frozen selection; transient acquisition staging is released
by the normal application path. Custody journals were not deleted to force a pass.

Both Broadcasts produced **10,000 real file reports** each, followed by an
independent Python verification of **all 10,000 files** against the external
manifest and unchanged reference digest
`1d604b9465c44815103437de83ab88cda22f18e388083139e3f83ef6197009c6`.
Every existing output inode was unchanged. Deliberate output corruption made the
same external verifier fail despite completed Jobs/reports. The successful
fixture and unprivileged database were then disposed normally.

Final Operations evidence: **20,004** Assignments, Demands, completed Attempts
and accepted Results; **180,042 Durable events**; **20,000 publication reports**;
no failed/interrupted persisted Attempts; zero additional Attempts per Demand;
empty transport queues at settled checkpoints. Admission samples never exceeded
**100**; sampled transport messages reached **102**, which includes orchestration
messages and is not an admission violation. Counts are persisted observations,
not invented totals from expected success. Total caught exceptions and transport
retry events are not fully instrumented and are **not claimed to be zero**.

### What actually costs time and space

- The two acquisition-driver intervals consume **4,190.389 s** (about **85.5%**
  of timed phase duration). Within those, real worker processes took **1,825.291 s**,
  admission commands **1,142.392 s**, and read-only CLI inspections **1,221.075 s**.
  Thus about **29.1% of acquisition-driver time is progress-inspection overhead**,
  not preservation. The serial harness started **1,200 bounded worker processes**;
  the empty-worker observation was **0.323 s**, but multiplying that by boots to
  subtract startup would be an estimate, not a measured handler-only time.
- Input payloads total only **571,135 bytes** in the independently pinned manifest.
  Vault logical files occupy **86.05 MB** and allocated regular-file blocks
  **737.29 MB**, with about **19 regular-file entries per original Item** including
  OCFL and custody bookkeeping. This demonstrates small-file metadata/block
  amplification on this OverlayFS backend. Directory inode allocation and physical
  device writes were not measured; these are not a total-device-space claim.
- Unchanged discovery took **279.980 s**, versus **102.256 s** for initial
  configuration/discovery. `AcquisitionCoordinator::reassociate()` walks canonical
  history and transaction candidates on that path. It is a concrete next profiling
  target, **not** a proven attribution of that entire interval to directory scans.
- SQL round-trip counts/latencies, individual inventory reads, CPU profiles and
  write syscalls were not captured. The measured driver costs do not establish
  which internal SQL call or OCFL operation dominates. Sampled queue peaks are
  not instantaneous bounds, and summed RSS can double-count shared pages. No
  parallel-worker, power-loss, hostile-writer or whole-tree-atomicity claim is made.

No application correctness defect or new architectural requirement was demonstrated.
The diagnosed changes were confined to instrumentation/acceptance: collector
warning isolation, worker-budget semantics, timing separation, a bounded-memory
canonical verifier, and fatal-safe checkpoint retention. Consider profiler-guided
work only after reviewing this baseline; do not add queues or persistence layers
to solve observer/startup costs.

Raw local report: `tmp/pr5-reports/pr5-10000-d86ef24215.json`; complete console log:
`tmp/pr5-reports/10000-retry-measured.log`. Report SHA-256:
`485d9f94a47fae4d6dd8de888d3f1d8b9d9f7343143c1aa3f72a89e23bda9de9`.
The generated corpus and raw local reports are not committed. The workflow below
retains its own credential-free reports as artifacts.

## Earlier observations and failure investigation

The completed, separately selected **100-Item** case passed **12,716 assertions**
in **119.21 s** (Pest wall time, including fixture setup/disposal). Timed phase
intervals total **118.091 s**. Peak summed process-tree RSS was **152,684 KiB**;
PostgreSQL grew from **7,765,015** to **11,729,943 bytes**. End-of-run Vault:
**1,904 regular files**, **860,676 logical bytes**, **7,385,088 allocated file
block bytes**. Directory inode blocks are not included in that last figure.

The first acquisition phase took **44.117 s**, unchanged acquisition **41.065 s**.
Across both, the **12** real `--limit=100` worker boots took **36.219 s**;
admission commands **23.598 s**; CLI progress inspections **24.746 s**. Settled
Item deltas per drain were **0, 0, 0, 34, 33, 33** on each pass. Empty-worker
baseline was **0.674 s**. This is evidence of substantial CLI/admission/observer
overhead, not evidence that payload copying or PostgreSQL storage is the main
bottleneck. No production optimisation has been made.

There were **204 Assignments, Demands, completed Attempts and accepted Results**
(two discovery Jobs, 200 per-file acquisitions, two Broadcast Jobs), no failed
or interrupted persisted Attempts, and **zero extra Attempts for an existing
Demand**. Sampled outstanding admission and summed transport messages each
reached **100**. End-of-phase transport queues were empty. Neither this nor
completed Attempts proves that no caught exception ever occurred; unrecorded
exceptions, Messenger retry history, instantaneous queue peaks, exact SQL
round-trip counts/timings and OS write syscall counts remain instrumentation gaps.

All **100** outputs were independently verified twice after source A removal;
all existing output inodes survived repetition. The unchanged scan retained
exact Item/Asset identities, v1 inventories, pins and payload hashes. The
deliberately corrupted output was rejected by the same independent Python
verifier. Retained raw report: `tmp/pr5-reports/pr5-100-a9cb169e35.json`; log:
`tmp/pr5-reports/100-measured.log`. These generated local files are not committed.

### 1,000-Item result

The separately selected **1,000-Item** case passed all acceptance gates. Timed
phases total **755.158 s**; checkpoint observation added **2.753 s**. Peak
summed RSS was **166,916 KiB**. PostgreSQL grew from **7,765,015** to
**25,279,511 bytes**. Vault: **19,004 regular files**, **8,605,516 logical bytes**,
**73,740,288 allocated file-block bytes**. There are exactly **1,000 Item v1**
and **1,000 Asset v1** archival versions; unchanged ingestion added none.

First acquisition: **325.550 s** (**3.072 Items/s**); unchanged acquisition:
**314.408 s** (**3.181 Items/s**). Across both passes, **120** worker boots took
**271.235 s**, admission commands **180.910 s**, and progress inspection
commands **187.336 s**. Empty-worker baseline: **0.653 s**. Inspection time is
acceptance-harness overhead, not preservation throughput. Admission includes
real application work as well as process startup. The empty baseline is a
comparison, not a measurement of every command's startup time: multiplying it
by boot counts would be an estimate, not a measured subtraction.

The final database has **2,004 Assignments, Demands, completed Attempts and
accepted Results**, **18,039 Durable events**, **2,000 publication reports**,
no failed/interrupted persisted Attempts and **zero extra Attempts per Demand**.
The upstream `durable_workflow_runs` table has zero rows on this execution
path; the actual durable evidence is its event journal, not that optional run
catalogue. Admission samples reached **100**; transport message samples reached
**102** (workflow messages are not additional per-file admission slots).

All **1,000** outputs were independently verified twice after source removal,
every output inode was preserved on repetition, and deliberate corruption was
rejected. Report: `tmp/pr5-reports/pr5-1000-d71711679c.json`.

The original shell supervisor timed out at 120 seconds, but the PHP process in
Podman continued. Its PID was explicitly checked, then supervised to exit using
Linux `pidfd_open` without restarting the application run. The retained report
was written only after the independent checks and negative control passed.
The detached console log is incomplete, so no exact Pest wall-time or assertion
count is claimed for this run. Later runs remove that shell timeout. A small
read-only live-progress probe was also exercised during this run; this is a
shared development machine, not an isolated performance lab.

### First 10,000-Item attempt: acceptance-harness memory failure

The independent source verifier accepted the pinned **10,000-file** manifest.
The real application completed discovery and first preservation: saved state
contains **10,000 preserved acquisitions**, **10,001 completed Attempts and
accepted Results**, no queued messages, and **74,365,975 PostgreSQL bytes**.
Source A remained present and there were **zero Broadcast runs**. This is not a
round-trip pass and says nothing about full-scale Broadcast correctness yet.

At the end of `Pr2Directory::assertPreserved()`, PHP exhausted its **128 MiB**
managed-memory limit while sorting the final expected-path set. The helper
still retained all **20,000 decoded OCFL inventories** as well as its path,
membership and reference arrays. This is classified as a **test-harness defect**,
not an application worker failure. The fatal exit bypassed `finally`, so the
fixture database and Vault were preserved; the initial in-memory phase report
was lost. The sampler observed **2,835.767 s** from first to final sample and
**179,264 KiB** peak summed RSS. Those are a failed-attempt span and sampled RSS,
not completed-workflow timing or pure application memory.

The smallest fix retains only the Object-ID-to-path index, validating each
inventory and its historical sidecar while walking the Vault, then loading only
the current Item/Asset pair while checking payloads. All exact-set, digest,
sidecar, v1, pin and ownership checks remain; a reread also checks Object identity.
The PHP memory limit and application worker behavior were not increased or changed.

A read-only regression against that retained real Vault checked all **10,000
Items and 10,000 Assets twice**, including equality of the two reference sets,
under the same **128 MiB** limit; peak PHP-managed allocation was **54,525,952
bytes (52 MiB)**. This is verifier evidence only, not a substituted application
run. Log: `tmp/pr5-reports/10000-verifier-regression.log`.

Retained failure evidence: `tmp/pr5-reports/pr5-10000-harness-oom.json`,
`tmp/pr5-reports/10000-oom-evidence/` (sampler trace, summary and independent
manifest), and `tmp/pr5-reports/10000-measured.log`. The original private fixture
is `/tmp/stashd-archives-90aec20ba97eb04e397a25cf` inside the PHP container; its
database is identified only by its private descriptor, never exported with
report artifacts. The complete acceptance test was then run again from a fresh
disposable fixture, without resuming after or fabricating the missing phases.

The corrected harness also passed the **100-Item** recheck: **12,959 assertions**,
**67.97 s** Pest wall time, **66.953 s** timed phases, and **149,232 KiB** peak
summed RSS (`pr5-100-44cb6f4088.json`). Checkpoint reports were created and removed
only after the final report was retained. The recheck was materially faster
than the initial 100-Item observation despite unchanged application code; the
cause of that runtime variance was not isolated. These are individual development
machine runs, not a controlled repeated throughput benchmark. Do not attribute
that speed change to the verifier memory fix or silently replace the initial
measurement with a faster sample.

## Explicit manual invocation

Use the intended isolated Lerd/PHP 8.5/PostgreSQL environment and stable
Tempest signing key. The fixture administrator must be configured as for
existing integration tests. Run **inside `lerd shell` in this checkout** so the
shell variables reach PHP (the host Lerd wrapper does not forward arbitrary
environment variables). Review each result before invoking the next command:

```sh
mkdir -p tmp/pr5-reports
export STASHD_TEST_PROCESSES=1 STASHD_PR5_REPORT_DIR="$PWD/tmp/pr5-reports"
./bin/test.sh tests/Stress/Integration/FilesystemRoundTripPr5StressTest.php \
  --filter='100 Items' --compact --colors=never
./bin/test.sh tests/Stress/Integration/FilesystemRoundTripPr5StressTest.php \
  --filter='1,000 Items' --compact --colors=never
./bin/test.sh tests/Stress/Integration/FilesystemRoundTripPr5StressTest.php \
  --filter='pinned 10,000 Items' --compact --colors=never
```

If the Lerd collector warning above is present, before testing create
`tmp/pr5-php-ini/99-pr5.ini` containing
`lerd.devtools_flag=/disabled-for-pr5-measurement` and export
`PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:$PWD/tmp/pr5-php-ini` in that shell.
This affects only that shell and its children, not other sites.

Run counts in order, review the 100 and 1,000 results before allowing a long
10,000 run. A timeout is a failed measurement or capacity finding, not a
license to lower the count or weaken the external oracle.

The stress report location must already exist. The fixture database, Vault,
Input and output are all disposable and isolated; source deletion never
targets a working directory. Unattributed fixture-named databases must
not be reset or dropped.

## Automated workflow integration

`.github/workflows/microtext-stress.yml` now executes the **real application**
test in three separate, ordered steps: **100 → 1,000 → pinned 10,000**. The
illustrative `cp -a` job is removed. The test itself retains independent source
and output/reference checks, actual source deletion, inode checks and corrupted
output rejection. A failed smaller case prevents larger cases from running.

Heavy execution remains limited to **merged PR closures targeting dev, release
publication and manual dispatch**. An ordinary PR update does not run stress;
closing an unmerged PR does not run it either. Routine CI still runs the small
regressions. All action revisions are pinned.

The job creates one private `.env` with a random **32-byte signing key once**,
before any acceptance process. Every worker and retry uses that same key; each
job has disposable independent fixtures, so no cross-job key continuity is needed.
The key and fixture administrator descriptor are deleted in an `always()` step.
Only `tmp/pr5-reports/*.json` is uploaded, including incomplete checkpoints:
counts, timings, synthetic run identifiers and resource samples, **not** `.env`,
credentials, private descriptors, source/Vault trees or arbitrary logs. Reports
are retained for **14 days**. Artifacts on a public repository are not a private
secret store; their credential-free scope is deliberate.

Initial execution budgets derive from the measured local cases: **5 min / 30
min / 180 min** steps versus approximately **2 / 12.6 / 82.1 min** observed;
the job allows **240 min** for their sum and setup/artifact margin. These are
conservative execution guards (roughly twice the observed local case durations
plus margin), not throughput promises. The manual hosted run below subsequently
completed in **1.62 / 14.18 / 140.82 min**, inside those guards; the 180-minute
full-scale guard has about **39 minutes** of observed runner headroom. The
240-minute job completed in about **157 minutes**. The guards are retained, now
supported by an actual hosted run rather than only local extrapolation. PHP's
128 MiB limit is explicitly configured in the hosted job too; no worker count
or memory limit was raised to obtain the pass.

### Hosted workflow and downloadable evidence

Manual [run 41815932228](https://github.com/Lost-and-Fonds/stashd/actions/runs/41815932228)
on **`b20dc9d` completed successfully**. Its real-application job executed all
three cases serially, using the branch's new workflow (the run list may still
display the workflow's older default-branch name). This establishes that the
workflow exercises Stashd, not just the Python fixture.

| Hosted observation | 100 Items | 1,000 Items | 10,000 Items |
|---|---:|---:|---:|
| Pest wall time, seconds | 97.48 | 850.72 | 8,449.48 |
| Timed phase total, seconds | 95.953 | 843.758 | 8,386.533 |
| Peak observed summed RSS, MiB | 164.75 | 174.44 | 237.22 |
| Final PostgreSQL / growth, MiB | 11.64 / 4.30 | 24.69 / 17.29 | 149.63 / 142.23 |
| Vault logical / allocated file blocks, MiB | 0.82 / 7.04 | 8.21 / 70.32 | 82.07 / 703.14 |
| Passed assertions | 12,959 | 127,124 | 1,268,774 |

The hosted full case again ended with **20,004 completed Attempts and accepted
Results**, no failed/interrupted persisted Attempts and zero extra Attempts per
Demand; sampled admission **100**, transport messages **102**, and **20,000 real
publication reports**. It recorded **180,048 Durable events**, versus 180,042
locally: journal-event totals are observations, not retry counters. The additional
six events are not attributed to six retries; detailed transport retry history
was not retained. The three runs' Vault file counts and bytes exactly matched
the local measurements. Different hosted/local runtimes are reported separately,
not averaged or presented as a controlled performance comparison.

Hosted logs contain three `Verified 10000 files against external manifest`
messages: the source check and both output checks. The negative control then
reports `content/size mismatch` and
`microtext: FAILED (2 problems; 10000 files checked)`, followed by the passing
acceptance result requiring that failure. Thus both positive and negative checks
ran inside the actual workflow.

The [downloadable artifact](https://github.com/Lost-and-Fonds/stashd/actions/runs/41815932228/artifacts/13327815942)
is **`pr5-measurements-41815932228-1`**, retained for 14 days. It was downloaded
and inspected: exactly three complete JSON reports, every report ending at
`negative_control_rejected`, and no env files, credential descriptors or credential
markers. Full-scale report **`pr5-10000-ebf60e3500.json`**, SHA-256:
`fb71d021f57303b15c625d23b80e7a8b26d00927bc6e74c5b4f75d8ab4d25f32`.
The checked-in tables preserve the principal observations after artifact expiry.

## QA and review

- Initial CI [41793399880](https://github.com/Lost-and-Fonds/stashd/actions/runs/41793399880)
  and harness-fix CI [41803925326](https://github.com/Lost-and-Fonds/stashd/actions/runs/41803925326)
  passed formatting, lint, static analysis and the complete routine suite.
- Local full **`composer qa` passed: 612 passed, 37,809 assertions**, with
  **42 pre-existing incomplete contracts**, and four existing informational Mago
  notices. The incomplete contracts are not reported as passed acceptance tests.
- Focused PR 1–4 Filesystem regressions passed: **35 tests / 11,989 assertions**;
  the canonical `STASHD_TEST_PROCESSES=4 ./bin/test.sh tests/Acceptance/Integration
  --filter='Filesystem.*Pr[1-4]Test' --compact --colors=never` also passed those
  **35 tests / 11,989 assertions in 307.85 s**.
- Independent Python fixture/mutation/RSS regressions passed: **14 tests**.
- Workflow YAML parsed; explicit checks confirmed ordered count filters, guarded
  triggers, no copy-only stage, timeout budgets and the narrow artifact path.
  `actionlint` is not installed; no actionlint pass is claimed.
- Lerd capture was temporarily disabled for ordinary local QA to avoid the
  collector's PHP 8.5 warning; it is restored afterward. Measured stress children
  used the isolated ini override described above.
- Integrated-workflow revision [CI 41815939321](https://github.com/Lost-and-Fonds/stashd/actions/runs/41815939321)
  passed; the manually dispatched heavy workflow and downloaded artifacts were
  independently inspected as recorded above.

## Current review gates

- [x] Genuine application contract added outside routine test discovery.
- [x] Pinned reference and independent exact-byte/tree verifier retained.
- [x] Source deletion, unchanged rerun, no-extra-version and output-inode
      assertions defined; corruption negative control retained.
- [x] Initial wall-clock, RSS, DB/Operations and Vault reporting instrumented.
- [x] Execute 100 and 1,000 locally; distinguish test-harness errors from
      product bottlenecks.
- [x] Execute **10,000** locally against the real system; retain the JSON
      report, full exact-tree proof and candid failure analysis.
- [x] Check metric semantics and explicitly disclose missing queue/exception/write
      observations rather than manufacture a comprehensive telemetry claim.
- [x] Replace the post-merge illustrative copy with the proven full application
      workflow and artifact retention.
- [x] Run formatter, lint, static analysis, independent fixture checks,
      focused PR 1–4 regressions and full Composer QA.
- [ ] Review results before merging. No automatic merge or unrelated fixes.

As in PR 4, the accepted guarantee is local **process-crash** recovery, not
power-loss durability, globally atomic publication or hostile-writer fencing.

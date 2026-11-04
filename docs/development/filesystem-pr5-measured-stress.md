# PR 5 — measured 10,000-Item application round trip

**Status: separate 100- and 1,000-Item runs passed. The first 10,000-Item attempt
hit a verifier memory defect after acquisition; that harness defect is fixed,
and a fresh complete run is underway. No 10,000-Item pass is claimed yet.** PRs 1–4 are merged. The 10,000-file
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

The first version is an **instrumented acceptance contract**, not a performance
benchmark. Further review should check which exception, retry, write and
per-queue depth values are actually observable through the existing stores
before claiming all requested measurements are complete. Record missing
measurements as missing, not as zero.

The JSON report is printed with a `PR5_METRICS` prefix and, when
`STASHD_PR5_REPORT_DIR` names an existing directory, copied there before the
fixture is disposed. Do not add generated reports or source files to Git.
Completed checkpoints are also atomically saved as a separate
`*.partial.json` report with status **incomplete**. This survives fatal errors
that bypass PHP `finally`; it is removed only after the complete report is saved.
An incomplete report is never a successful acceptance result.

## Measurements so far

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
report artifacts. The complete acceptance test will run again from a fresh
disposable fixture, not resume after or fabricate the missing phases.

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

## Existing automated workflow and changeover gate

`.github/workflows/microtext-stress.yml` currently:

- proves the pinned Python fixture with an **illustrative source-to-output
  copy** in its corpus job; that proves the *instrument*, not Stashd;
- runs the previous real 100/101/1,000 directory contract separately after
  merged PRs, release publication or manual dispatch.

PR 5 should **replace the illustrative copy** with the genuine application
stress *only once its full 10,000-Item run has been measured and reviewed*.
Keep the original independent verifier/reference/negative control, remove the
copy-only false product-success interpretation, and wire the real entry
points into the post-merge/release/manual workflow—not normal pull-request CI.
Provide an appropriately justified job time/resource budget based on actual
runner evidence; do not invent a throughput number. Persist reports as
private workflow artifacts only after confirming they contain no secrets.
The stable Tempest `SIGNING_KEY` must also be configured for the stress job
following PR 4's workspace-authentication change.

## Current review gates

- [x] Genuine application contract added outside routine test discovery.
- [x] Pinned reference and independent exact-byte/tree verifier retained.
- [x] Source deletion, unchanged rerun, no-extra-version and output-inode
      assertions defined; corruption negative control retained.
- [x] Initial wall-clock, RSS, DB/Operations and Vault reporting instrumented.
- [x] Execute 100 and 1,000 locally; distinguish test-harness errors from
      product bottlenecks.
- [ ] Execute **10,000** locally against the real system; retain the JSON
      report, full exact-tree proof and candid failure analysis.
- [ ] Check actual metrics semantics and cover missing queue/exception/write
      observations before presenting a comprehensive scale report.
- [ ] Replace the post-merge illustrative copy with the proven full application
      workflow and artifact retention.
- [ ] Run formatter, lint, static analysis, independent fixture checks,
      focused PR 1–4 regressions and full Composer QA.
- [ ] Review results before merging. No automatic merge or unrelated fixes.

As in PR 4, the accepted guarantee is local **process-crash** recovery, not
power-loss durability, globally atomic publication or hostile-writer fencing.

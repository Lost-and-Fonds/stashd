# Filesystem stress CLI

Run the real **100-Item** acceptance experiment, serially, without using your
working database, Vault or source tree:

```sh
php tempest stress:filesystem
php tempest stress:filesystem --count=100 --no-interaction
mkdir -p tmp/filesystem-stress-reports
php tempest stress:filesystem --count=100 --no-interaction --json \
  --report-dir=tmp/filesystem-stress-reports > tmp/filesystem-stress-result.json
```

Without a count, a terminal offers a Tempest confirmation and report-directory
prompt. An unattended run needs an explicit `--count=100`. Other counts are
refused by the CLI. `--json` disables prompts and decoration: stdout is exactly
one final JSON report; phase messages, verifier diagnostics and report filenames
go to stderr. A failed or interrupted run exits nonzero. The expected corruption
diagnostic is followed by success **only when that negative control was rejected**.

## Prerequisites and safety

- Composer development dependencies, PHP 8.5, Linux `/proc`, `pcntl`, Python 3,
  and the existing PostgreSQL integration fixture administrator are required.
  The command refuses production and staging, even with unattended flags.
- Interactive Tempest prompts require `stty`, `tput` and a supported `TERM`.
  On Lerd, `lerd php:pkg add ncurses` installs `tput` persistently in PHP images;
  run it on the host, then reopen `lerd shell`. This declares the package for all
  PHP versions, rebuilding the current version now and others on their next use.
- Configure the private, ignored `tmp/transaction-projection-admin.json` as in
  the existing integration tests: `dsn` must be
  `pgsql:host=lerd-postgres;port=5432;dbname=postgres`, with explicit local
  administrator `username` and `password`. Keep the file mode `0600`. Never put
  administrator credentials in an application connection or a saved report.
- Configure one stable `SIGNING_KEY` before running. The CLI never generates or
  rotates it. Tempest loads it once and child workers inherit it, preserving the
  signing continuity required for durable messages and retries.
- The report directory must already exist and be writable. The default is
  `STASHD_FILESYSTEM_STRESS_REPORT_DIR`, otherwise the current directory.
  Prefer an ignored `tmp/` directory. Reports contain no credentials, DSNs,
  signing keys, private descriptors or raw exception messages.
- There is deliberately **no** flag accepting an existing source, Vault, database
  or fixture root. Each experiment creates a random private workspace and a new
  unprivileged database/role through the existing fixture administrator.
  Cleanup requires the process's private ownership marker. Linked ancestors and
  traversal are refused; recursive removal never follows links. Deletion failures
  prevent a success report. Never manually drop an unattributed fixture database.

SIGINT/Ctrl+C and SIGTERM cancel the run. The supervisor drains child output,
kills/reaps its active CLI child on cancellation, stops/reaps its RSS sampler,
and disposes its owned fixture. The sampler ignores terminal-wide cancellation
while the parent finishes evidence; its stop marker or parent disappearance still
ends sampling. Repeated cancellation is ignored during cleanup. SIGKILL, machine
failure and fatal PHP errors cannot guarantee cleanup: an `incomplete` report and
atomic checkpoint report survive, and private fixture artifacts may remain.

An initial incomplete report exists before provisioning. Final `passed` is saved
only after all verification **and fixture cleanup**. Ordinary failures retain a
failed report; a cleanup failure is explicitly `cleanup: not_confirmed`. Reports
and checkpoints are evidence, not instructions to reset any other installation.

## One workflow, three responsibilities

- `tests/Support/Integration/FilesystemStressRunner.php` owns the fixture and
  existing application commands, bounded serial worker drains, unchanged scan,
  source removal and both Broadcasts. It reuses the existing independent OCFL,
  Item–Asset pin, native digest and manifest witnesses. No archival writes are
  implemented by the harness.
- `FilesystemStressObserver.php` reads persisted discovery and Operations state,
  observes worker activity, samples RSS, and retains the existing phase, queue,
  database and Vault measurements. Discovery is a distinct-source count;
  admitted/settled refer to the current acquisition pass. Outstanding/failed Jobs
  are persisted Assignment counts. `preserved` advances only after physical OCFL
  and exact-byte checks; `verified` only after the independent Python output check.
  Neither advances because Jobs happen to be complete.
- `app/Development/FilesystemStressPresenter.php` renders the typed progress
  snapshot. Tempest owns the command and prompts. Its installed
  `ProgressBarComponent` counts/consumes an iterable and cannot accept externally
  sampled worker updates; the installed Symfony `ProgressBar` provides that narrow
  capability. It redraws one display rather than printing every Item. Revisit if
  Tempest gains a manually updated progress component. No dependency was added.
  Compact metric rows wrap to terminal width, with the bar/count and phase units
  on separate lines. Verifier and failure diagnostics clear and restore the bar
  so their output cannot leave its cursor on the wrong line.

State is sampled about once a second and on worker transitions; process output is
drained every 250 ms. No Vault walk occurs on each refresh. Unknown measurements
are `?`/`null`, not fabricated zeros. `settled/total-s` is the current pass's settled
count divided by total experiment elapsed time, including setup/checks—not pure
worker throughput. It resets when acquisition changes pass. Phase rates in JSON
retain their existing meanings. RSS is the observed sum for the CLI and its
descendants, excluding the sampler and PostgreSQL; shared pages can double-count.
Checkpoint SQL/Vault walks are separately timed. Live observation and process
polling add overhead to phase intervals. No ETA or performance improvement is claimed.

The bar resets for every phase and names its units: distinct discovered sources,
settled Jobs, checked publication reports, or independently checked Items/files.
Job settlement and publication reports are explicitly **not** byte verification.
Whole-corpus checks advance only when the unchanged checker returns successfully;
there is no invented per-file progress during those checks. Repeat acquisition
and discovery use their new Input run rather than reusing earlier completion.
Removal, corruption rejection and cleanup use one-step counters. Cleanup remains
pending until disposal succeeds; only then may the final summary say **PASS**.

`ProcessTreeRssTrace` live refreshes seek to the last consumed byte and update an incremental maximum
from new, newline-terminated samples. A partial final line waits for the next
refresh. Completed history is not reparsed. Final report construction still reads
the complete trace independently to calculate each phase's peak, preserving the
existing report fields and measurement semantics.

The installed Tempest process documentation, `GenericProcessExecutor` and
`InvokedSystemProcess` were inspected. This extraction retains the existing
acceptance probes' process-resource handles and concurrent-test callers rather
than changing their lifecycle/API across the older regression helpers. The small
supervision extension is tested for exact exit status, callback failure, reaping
and real Ctrl+C; revisit a framework-process migration when those probes move
together. Likewise, Tempest's `Database`/`Query` APIs were inspected: observation
keeps the independently identity-checked fixture PDO, rather than resolving the
parent application's working connection. The queries are read-only PostgreSQL
aggregates/catalogue probes with bound run identifiers, not product persistence
or a second database abstraction. Revisit if the acceptance fixture provides a
similarly isolated Tempest connection. Application writes still use normal services.

## Acceptance and report compatibility

The independent `tools/fixtures/microtext.py` verifier, reference and deliberate
corruption control are unchanged. A pass requires 100 distinct canonical Items
and Assets, exact v1 inventories/pins/bytes, no new archival versions on unchanged
rescan, source A absent before publication, independently verified output twice,
stable output inodes, and independent corruption rejection. The deliberately
damaged output is disposable and removed during cleanup.

`tests/Stress/Integration/FilesystemRoundTripStressTest.php` invokes the actual CLI
for 100 Items, checks stdout JSON against the saved report, and checks persisted
counts captured before disposal. The shared runner performs all original
independent acceptance assertions. Larger explicit Pest cases still use that same
runner; **larger CLI workloads and concurrency are not enabled**. The post-merge
workflow keeps its existing guarded, ordered Pest entry point; only semantic
names/paths and its shared implementation change here.

New reports use `format: stashd-filesystem-stress-1` and
`filesystem-stress-<count>-<random>.json`; test logs use
`FILESYSTEM_STRESS_METRICS`. Existing measurement keys and checkpoint names remain
compatible. Historical `stashd-pr5-stress-1` reports and their hashes are not
rewritten. Readers comparing historical evidence should accept either format
identifier and the same measurement fields. `STASHD_PR5_REPORT_DIR` remains only
as a deprecated fallback for existing direct stress-runner automation; the new
variable wins, and using the fallback emits a warning. Old class/test filenames
are not retained as competing implementations.

## Local validation and comparison

The initial renamed 100-Item Pest case ran the actual CLI and passed in **94.16 s**.
Its parent checks include parseable/decorated-output-free stdout, matching saved
JSON, final persisted counts, cleanup and the corruption-rejection checkpoint.
The independent assertions run inside the CLI, so Pest's parent assertion count
does not include those child assertions.

| Observation | Original local 100 baseline | CLI acceptance run |
|---|---:|---:|
| Timed phase total | 118.091 s | 92.983 s |
| First / unchanged acquisition | 44.117 / 41.065 s | 29.872 / 35.650 s |
| Peak observed summed RSS | 149.1 MiB | 160.14 MiB |
| Final PostgreSQL bytes | 11,729,943 | 12,074,007 |
| Vault files / logical bytes / allocated file blocks | 1,904 / 860,676 / 7,385,088 | identical |
| Completed Attempts / accepted Results | 204 / 204 | 204 / 204 |
| Publication reports / extra Attempts | 200 / 0 | 200 / 0 |
| Acquisition worker boots / sampled admission maximum | 12 / 100 | 12 / 100 |

The original corrected-harness recheck was already **66.953 s** timed phases.
These are shared-machine observations, not controlled repeats. The CLI includes
its own bootstrap and live-observer overhead; do not infer a speedup from the table.
The original RSS root included Pest; the new root is the CLI and excludes the
supervising Pest process, so their memory measurements are not identical scopes.
Report: `tmp/filesystem-stress-100-e322bf6762.json`, SHA-256
`795eb1267964d45b99dd4ed92a78f9d3f0d91c1fb7a0bbfc9d1773d6ae105368`.
No generated report or private fixture configuration is committed.

Also validated: focused Filesystem regressions **35 passed / 11,350 assertions**;
independent Python fixture/sampler regressions **15 passed**; full `composer qa`
passed after the review fixes: **634 passed / 37,254 assertions**, with **42 pre-existing incomplete
contracts**, clean formatting/lint and four existing informational static-analysis
notices. Incomplete contracts are not passes. Assertion totals differ from historical reports because
shared command checks now use PHPUnit assertions directly instead of Pest wrappers.

The review-fix 100-Item CLI recheck passed **19 parent assertions in 98.44 s**,
including phase-reset assertions through repeat publication, corruption checking
and cleanup. Focused progress/tail-reader tests passed **20 tests / 123 assertions**;
they cover bar resets despite prior verification, unknown counts, appended/partial
RSS lines, retained maxima, no rereading of consumed history and malformed JSON.
New report: `tmp/filesystem-stress-100-4f1736a082.json`, SHA-256
`4b1af2a11143cb3702863a824ae911f9cabdacead6780339d0e1eb4747b95ac6`.
The full QA rerun took **428.71 s**. These remain uncontrolled observations, not a
performance-improvement claim.

A later terminal fix was validated with Lerd's persistent `ncurses` package
installed. Tempest's rich confirmation and report-directory prompts now render
without missing-tool warnings. An **80-column** pseudo-terminal completed the
entire 100-Item interactive run: exit 0, 21 recorded phases, all independent
checks passed and cleanup completed. A separate real terminal Ctrl+C while a
worker was active returned exit 1, retained failed phase evidence and completed
cleanup. Width regressions cover **40, 80 and 100 columns**; focused tests passed
**25 tests / 166 assertions**, including rich-prompt decline and diagnostic redraw.
Interactive report: `tmp/filesystem-stress-100-4fea088a1d.json`, SHA-256
`bc59a88eb69c102d39d4b2c9df52bb96f50dfdf80dad23ffed8c92e533d39e2f`.

Lerd's PHP 8.5 collector deprecation initially contaminated child JSON and correctly
failed acceptance. Observed fixture CLI children now set
`-d lerd.devtools_flag=/disabled-for-filesystem-stress-measurement` when Lerd's
extension is present. This keeps collector work/output out of the measurement;
normal extensions, validation and JSON parsing remain unchanged. Ordinary fixture
callers without a progress observer are unchanged. Both the full interactive run
and the JSON acceptance recheck ran **without a PHP ini override**. JSON acceptance
passed **20 parent assertions / 155.62 s**, including no collector/deprecation noise.
JSON report: `tmp/filesystem-stress-100-d9fa8592e0.json`, SHA-256
`04aad23f04a244bae8b583f4d7eaaac68dc8ca6f753b04b00ab2bec2c38b098d`.

Full QA passed **639 tests / 37,315 assertions in 425.72 s**, with the same
42 incomplete contracts and four informational analysis notices. The ordinary
suite still needs the earlier private collector-disable ini override: a run
without it failed 40 unrelated JSON checks and reported 62 collector deprecations.
That environment defect is not repaired globally by this stress CLI change.
No global capture setting, working database or application signing key was changed. See the
[historical baseline](filesystem-pr5-measured-stress.md) for storage/measurement
limits. The CLI's 1,000/10,000 workloads, persistent workers and concurrency were
not run or added here. Static analysis retains the repository's existing exclusion
of `tests/**`; the command has two narrow annotations for its optional dev-only
runner dependency, not broad suppression of application analysis.

## Identified historical naming debt (not renamed here)

- `tests/Support/Integration/Pr1RoundTrip.php`, `Pr2Directory.php`,
  `Pr3Mutation.php`, `Pr4Recovery.php` describe round trips, directory acceptance,
  mutation witnesses and recovery, respectively. The new runner reuses these
  existing witnesses rather than cloning them under new names.
- Acceptance files `FilesystemRoundTripPr1Test.php`,
  `FilesystemDirectoryPr2Test.php`, `FilesystemMutationPr3Test.php`,
  `FilesystemRecoveryPr4Test.php`, `FilesystemWorkspacePr4Test.php`, and stress
  files `FilesystemDirectoryPr2StressTest.php` / `FilesystemMutationPr3StressTest.php`.
- `PR4_INITIALIZATION_FAILURE`, `PR4_WORKSPACE`, `PR4_ENTRY_COUNT` in
  `workspace-worker.php` and its test callers; the `PR2` SQL guard error label in
  the directory stress test; historical comments and documentation invocation filters.

Those names should be cleaned up together with their callers in a separate change.
PR/milestone identifiers remain appropriate in historical evidence and review notes,
not in new permanent code identifiers.

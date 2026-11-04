# PR 5 — measured 10,000-Item application round trip

**Status: initial acceptance-first draft; no 10,000-Item application run has
been performed or verified in this PR.** PRs 1–4 are merged. The 10,000-file
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

## Explicit manual invocation

Use the intended isolated Lerd/PHP 8.5/PostgreSQL environment and stable
Tempest signing key. The fixture administrator must be configured as for
existing integration tests. The command intentionally runs all three sizes:

```sh
mkdir -p tmp/pr5-reports
STASHD_TEST_PROCESSES=1 STASHD_PR5_REPORT_DIR="$PWD/tmp/pr5-reports" \
  ./bin/test.sh tests/Stress/Integration/FilesystemRoundTripPr5StressTest.php \
  --compact --colors=never
```

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
- [ ] Execute 100 and 1,000 locally; distinguish test-harness errors from
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

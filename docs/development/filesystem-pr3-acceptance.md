# PR 3 acceptance: filesystem mutation and historical reconstruction

**Status: routine and full mutation acceptance verified against the existing implementation.**
[PR #87](https://github.com/Lost-and-Fonds/stashd/pull/87) and
[PR #89](https://github.com/Lost-and-Fonds/stashd/pull/89) are merged into `dev`.
The substantive PR 3 tests and full 400-file mutation workload pass without
production changes; see the observed results below. The stress test remains opt-in.

The authoritative requirements are
[the five-PR plan](../plans/filesystem-round-trip.md) and the
[separate mutation corpus requirement](microtext-fixture.md).
The pinned 10,000-file `reference-v1.json`, original Microtext generator
and external verifier are **not modified**.

## Three different snapshots

The baseline fixture's filenames encode their **original** content hash,
while Filesystem Input identifies each source by `SHA256(relative path)`.
Changing a file's bytes must keep its path and original name intact so the
second discovery retains its Item identity. The original Microtext verifier
intentionally rejects this changed file; do not weaken it.

`tools/fixtures/microtext_mutations.py` adds a separate, deterministic
mutation fixture with **disjoint** changed, renamed and removed index ranges.
Its manifests are stored outside the disposable source root.

- **Frozen original:** the normal Microtext manifest, captured before any
  mutations. It describes the historic `v1` selection, and the existing
  independent Microtext verifier checks the old Broadcast directory.
- **After-source:** only the files currently present on Input A. Modified
  paths have new bytes, renamed files appear only at their new paths, and
  removed files are absent.
- **After-preservation:** the expected contents of Stash membership after
  rescanning and acquiring. It retains original paths and bytes for removed
  and renamed-away Items, updates the changed paths, and adds new/renamed
  paths. Their history is **not deleted** by discovery. A separate external
  SHA-256 verifier checks the new Broadcast directory.

An exhaustive scan can establish that a source is absent **from that scan**.
It is never permission to delete its Item, OCFL history or Stash membership.
A partial scan cannot even establish exhaustive absence.

For a baseline of `N` and mutually disjoint mutation groups `A` added,
`C` changed, `R` renamed and `D` removed:

| Evidence | Expected count |
| --- | ---: |
| Before source/Items | N |
| After-source files and discoveries | N + A - D |
| After-preservation Items and output files | N + A + R |
| Existing Item revisions updated | C |
| New Item identities | A + R |
| Missing/renamed-away original Items still preserved | D + R |

The additional `R` Items are intentional: rename inference from unchanged
content is expressly out of scope. The renamed-away original Item remains
available alongside the newly named Item.

## Routine Pest cases

`tests/Acceptance/Integration/FilesystemMutationPr3Test.php`:

1. Generate and acquire **9** files through real PR 2 Operations Jobs.
2. **Freeze** the initial Stash selection, with exact `v1` references, but
   do **not publish it yet**.
3. Add 2, modify 3 at their existing paths, rename 2 without byte changes
   and remove 1. An external source oracle must match the **10** real files.
4. Run a new **exhaustive** discovery and per-file acquisition through real
   Tempest/Messenger/Durable entry points. Membership becomes **13** Items.
5. Independently inspect physical OCFL inventories, sidecar checksums,
   exact Item/Asset version pins and byte hashes. Changed paths must have
   original `v1` and new `v2` of the **same** Item and Asset; unchanged,
   removed and renamed-away paths must stay `v1`; renamed new paths and
   added paths must have new Items at `v1`.
6. Freeze the current exact-version selection. Verify that the first
   selection remains `v1` even though those Items now have newer heads.
7. **Delete source A** before publishing **either** selection into two
   independent Broadcast roots. Check old B with the unchanged Microtext
   oracle and new B with the separate mutation oracle.
8. Negative control: old verifier must reject the new selection, and
   the new verifier must reject the old selection. A publisher that quietly
   switches frozen old revisions to latest heads fails the old oracle.

A second test removes one original source and adds a symlink. The new
discovery must report `partial` and cannot erase earlier history. Removing
the unsafe link allows a later `exhaustive` scan; it still cannot delete a
previously preserved Item. The full original output must reconstruct after
source deletion.

No test fabricates accepted archival versions or `Result` rows. All
preservation and publication goes through public CLI commands and real workers.

## Separate full mutation run (not routine CI)

`tests/Stress/Integration/FilesystemMutationPr3StressTest.php` exercises
the full required mutation count on a disposable **400-file** baseline:

- **100 added, 200 changed, 50 renamed, 30 removed**.
- After-source: **470** files, acquired through actual bounded per-file Jobs.
- After-preservation: **550** distinct Items, including renamed-away and
  missing originals, with exactly **200** new `v2` histories.
- Original 400-Item `v1` selection, frozen before mutation, and current
  550-Item selection both built after source deletion and verified
  independently.

Run on demand through the existing Lerd test harness, **not** ordinary PR CI:

```sh
python3 -m unittest discover -s tools/fixtures -p 'test_microtext_mutations.py' -v
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/FilesystemMutationPr3Test.php
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Stress/Integration/FilesystemMutationPr3StressTest.php
```

The full mutation run is now proven locally; it remains opt-in. This review
follow-up does not change the post-merge/release workflow.
The 10,000-file application stress run remains PR 5. Do not infer throughput
from this acceptance contract.

## Review gates and limits

- **Review naming/semantics before implementing.** Current PR 2 exposes
  `input:discover`, `input:acquire`, `stash:items`,
  `stash:broadcast:select` and `broadcast:build`; this design requires no
  new CLI or persistence tables.
- A previously published historical selection is frozen; a selected
  `Item v1` must always pin its historical `Asset v1`. A newer head
  cannot silently replace exact version references.
- No rename detection, content-address identity remapping, automatic
  deletion/pruning, history database or new archival-history store.
- No actual process crashes or custody-failure matrix: those belong to PR 4.
- Test roots and databases are disposable, and mutation manifests never live
  inside the scanned source directory.
- The 11 fast Python Microtext regressions, including three mutation-oracle tests,
  passed in [GitHub CI run 41595049598](https://github.com/Lost-and-Fonds/stashd/actions/runs/41595049598).
  That run stopped at Mago formatting (one PHP file), before PHP syntax,
  static analysis or Pest. Local validation below closes the routine and full
  PR 3 mutation gates, not the later PR 4/5 gates.

## Observed PR 3 validation

Branch `feat/filesystem-pr3-mutations-history` starts from the reviewed #91
contracts (`4131ba4`). Its production code, Composer files and PHPUnit suites
match merged `dev` (`39da695`). Only Mago formatting of `Pr3Mutation.php` and
validation/plan documentation were needed beyond those acceptance contracts.

### Failure classification

1. **Formatting:** installed Mago reformatted the Python argument list in
   `tests/Support/Integration/Pr3Mutation.php`. No assertions changed.
2. **Environment prerequisite:** the first focused run failed twice with
   `An explicit private local projection test-admin configuration is required.`
   Provisioning the existing ignored 0600 fixture configuration resolved it.
   Each scenario creates and disposes its own unprivileged PostgreSQL database
   and role; application database settings and working Vaults were not changed.
3. **Environment instrumentation:** the next run failed JSON decoding because
   `/usr/local/etc/lerd/devtools-collector.php:644` printed PHP 8.5's
   `ReflectionProperty::setAccessible()` deprecation before an otherwise valid
   discovery receipt. Disabling Lerd debug capture resolved it. No JSON parsing
   or application error checks were weakened. A temporary diagnostic change
   that exposed the raw output was removed.
4. **Product behavior:** both substantive contracts then passed unchanged:
   **2 tests, 2,048 assertions**. Same-path changes retain Item/Asset identity
   with v1/v2, renamed paths get new Items, missing originals remain members,
   unchanged Items stay at v1, and both frozen outputs reconstruct after source
   deletion. There is no demonstrated missing production behavior to implement.

### Latest-head negative control

The independent Python latest-head substitution test passes by rejecting the
wrong historical bytes. An additional temporary production mutation in
`BroadcastInvocation::next()` replaced every frozen Asset revision and size
with its current head and current size before granting reads. Both real
Broadcast Jobs still completed after source deletion, but the acceptance test
failed at `FilesystemMutationPr3Test.php:109`: the original external manifest
rejected `historical-output` (verifier exit 1 rather than 0). This demonstrates
that successful Jobs and a correct file count cannot hide latest-head reads.
The mutation was removed; no production diff remains.

A narrower initial mutation changed only `AssetArchive::readStream()` to read
head. It failed earlier at the existing stream-length guard, because the frozen
size no longer matched. Updating both the revision and size for the second
control ensured that the independent historical oracle itself was exercised.

### Commands and results

All PHP runs used Lerd's PHP 8.5 environment and the existing local PostgreSQL
fixture. The installed dependencies and existing Tempest/Catalogue/Operations/
preservation facilities were unchanged.

| Command | Observed result |
| --- | --- |
| `lerd composer fmt` | One file formatted; subsequent `lerd composer github:fmt` passed. |
| `python3 -m unittest discover -s tools/fixtures -p 'test_microtext_mutations.py' -v` | 3 passed, including latest-head substitution rejection. |
| `python3 -m unittest discover -s tools/fixtures -p 'test_microtext*.py' -v` | All 11 passed. |
| `STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/FilesystemMutationPr3Test.php` | 2 passed, 2,048 assertions after environment setup. |
| Same focused test with `--filter='modified paths'`, temporary head-and-size substitution | 1 expected failure at the historical byte oracle, 1,510 assertions. |
| Combined focused regressions below, after restoring production code | 38 passed, 6,689 assertions, exit 0. |
| `lerd composer qa` (repeated after restoring negative controls) | Exit 0: formatting/lint passed; 586 passed, 42 existing incomplete, 29,082 assertions, eight processes. Analysis reported four existing informational notices, no errors. |

The combined focused command was:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/{FilesystemRoundTripPr1,FilesystemDirectoryPr2,FilesystemMutationPr3,Acquisition,Broadcast,FilesystemPublication,Discovery}Test.php --colors=never
```

The first attempt exceeded the shell tool's 120-second output deadline; the
complete rerun used a longer deadline and finished in 524.97 seconds while QA
also ran. This is execution evidence, not a performance benchmark.

After testing, both private test-admin configurations created for this run were
removed and Lerd debug capture was restored to its original enabled state.
No test PHP processes remained. Final PostgreSQL inspection found two generated
fixture-named databases without active sessions or retained descriptors linking
them to this worktree. Their ownership could not be established safely, so they
were left untouched rather than deleting potentially unrelated fixtures.

### Review follow-up: full mutation workload and CI

[CI run #59](https://github.com/Lost-and-Fonds/stashd/actions/runs/41739433866)
completed **green** on `017594e`, including Python, formatting, lint, analysis
and the complete Pest suite. This confirms the run named in the #92 review;
it is not a claim about a later documentation-only commit's CI run.

The review explicitly requested the remaining opt-in workload:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Stress/Integration/FilesystemMutationPr3StressTest.php --colors=never
```

Result on the unchanged `017594e` PHP/tests in Lerd PHP 8.5/PostgreSQL:
**1 passed, 57,329 assertions, exit 0, 270.63 seconds**. No failure or production
change was needed. This proves the 400-file baseline with 100 additions,
200 same-path modifications, 50 unchanged-byte renames and 30 removals:

- Exactly **470** live sources after mutation and **550** preserved Items.
- Stable original Item/Asset identities with retained v1 and exactly **200**
  matching v2 histories; renamed paths have distinct Items and old paths survive.
- Frozen exact Item revisions and matching physical Asset pins, independently
  checked against native OCFL inventories and payload hashes.
- Both the **400-Item historical** and **550-Item current** outputs published
  after source deletion and accepted by their separate external manifest oracles.

The private administrator config created for this run was removed, and Lerd
capture was restored to enabled. Before/after SQL inspection showed exactly the
same two previously observed, unattributed fixture-named databases, with no active
sessions; the stress run left no additional fixture database or role. They remain untouched.
The stress test still lives outside `phpunit.xml`'s routine suites.

The [plan](../plans/filesystem-round-trip.md) now records merged PR 2 and validated
PR 3. Keep #92 draft for merge review. After #92 is merged, close superseded
tests-only draft #91 rather than merging both; no merge or closure was performed.

No new queue, history store, coordinator, migrations, runtime dependencies or
public API were added. No throughput, PR 4 recovery-matrix or PR 5 10,000-Item
result is claimed. Existing incomplete tests and informational analysis notices
remain visible rather than being suppressed.

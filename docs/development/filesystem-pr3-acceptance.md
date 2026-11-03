# PR 3 acceptance: filesystem mutation and historical reconstruction

**Status: acceptance-first tests, not implementation.** [PR #89](https://github.com/Lost-and-Fonds/stashd/pull/89)
is merged into `dev`; this tests-only branch targets `dev`. Do not merge the
intentionally incomplete contracts alone.

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

Only after the implementation and tests are proven should the opt-in
stress suite be integrated into the post-merge/release workflow.
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
  static analysis or Pest. Full PHP QA and Lerd integration have **not** been
  demonstrated for this tests-only branch. Fix formatting and establish the
  genuine failing acceptance baseline before claiming PR 3 behaviour is complete.

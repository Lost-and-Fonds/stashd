# Phase 2 independent verification evidence

The [original verification report](verification-report.md) is preserved byte-for-byte for PR #59 at `3534a4c37aa77f53d7f331d97808eb05859b1e00`, against frozen Phase 1 `680476b57f87b5d356275074688c35eaadea4313`. Its commands, results, initial failures and limitations are historical observations, not results recomputed during preservation. The original report's SHA-256 and detailed provenance are in [provenance.json](provenance.json).

**Verifier:** OpenAI Codex assistant `/root` in the user conversation that requested independent verification. This was fresh execution with a separately written Python physical reader, on the same local machine/Lerd runtime and in the same conversation after the author reports. It was not a separately identified human, external runner, or signed independent certification. The Git committer is the uploader; it must not be mistaken for a human verifier. No stable external run/session identifier or model attestation was available to preserve.

The original integrity manifest was checked successfully for all **517 files** before copying. The report is unchanged. Its already-disclosed missing evidence remains missing: the interrupted first full test run has recovered session totals but no separately retained complete logfile. Nothing was reconstructed to fill that gap.

## Preserved results

- Frozen acceptance: **48 passed, 7,607 assertions**; five protected files unchanged.
- Supplementary transaction/custody/concurrency/publication faults: **113 passed, 5,738 assertions**.
- Complete suite: **300 passed, 33 pre-existing incomplete, 14,121 assertions**, exit 0.
- Independent physical reader: **1,097 checks passed**, including actual SIGKILL, recovery, exact raw journal/OCFL bindings, replay without extra publication, cleanup interruption and fractional metadata.
- PostgreSQL cleanup: zero disposable databases, roles and fixture credential descriptors; private test-admin files removed.
- Repository QA: **FAIL**, existing unsafe-finally lint blocker; separate analysis **1,163 errors, 193 warnings, 11 help messages**. No source mutation or silent fixes.

The complete report and raw log bytes are retained here. All logs and captured patches are losslessly gzip-compressed, preserving their original whitespace and avoiding large generated diagnostic diffs. Decompress the logs to resolve the original report's `.log` links. All **477 physical evidence files**, including the actual captured Vault stages and process events, are preserved in [physical-evidence.tar.gz](physical-evidence.tar.gz) (about 70 KiB), instead of committing hundreds of generated filesystem entries. Summary/checklist files are also available directly under [physical-evidence/](physical-evidence/).

To inspect the native snapshot files, resolve the original report's remaining `physical-evidence/` links, or rerun the standalone reader:

```sh
cd docs/preservation/verification/pr59-3534a4c
tar -xzf physical-evidence.tar.gz
find . -name '*.log.gz' -exec gzip -dk {} \;
python3 read_physical_evidence.py physical-evidence
```

The reader was rerun successfully after the evidence was copied; no Stashd Python dependencies are required. PHP diagnostic producer sources are historical, temporary scripts: they were executed from the ignored application `tmp/` directory as recorded, not from this documentation directory. The archive contains disposable archival bytes and process observations, not PostgreSQL databases or private credentials. No application `.env` file is committed.

`artifact-sha256.json` is the original bundle's integrity manifest; after archive extraction its recorded relative paths are available. `preservation-sha256.json` covers this preserved representation, including the archive and added provenance, and excludes itself.

## Focused QA prerequisite — PR #61

[PR #61](https://github.com/Lost-and-Fonds/stashd/pull/61) changes only `ObjectWriteLockTest.php`: the child-cleanup loop checks its running state in its condition rather than using `break` inside `finally`. It keeps the status check, conditional 10 ms wait, two-second deadline, termination fallback and process reaping. It changes no production code or frozen transaction files.

The fix is based on `dev` `9045241`, not the Phase 2 branch. Therefore its totals are different baselines, not a reduction of Phase 2's 1,163 errors:

| Check | Result |
|---|---|
| Affected canonical tests before/after | 2 passed, 8 assertions, exit 0 |
| Focused lint before | exit 1: unsafe-finally |
| Focused lint after | exit 0: one existing warning, one help message |
| Full read-only lint on final patch | exit 0: 4 warnings, 6 help messages |
| Complete canonical dev-based suite | 184 passed, 33 pre-existing incomplete, 2,779 assertions, exit 0 |
| `composer qa` | format/lint pass; analysis fails: 1,037 errors, 142 warnings, 9 help messages; test stage not reached |
| Separate analysis of final patch | identical 1,037 errors, 142 warnings, 9 help messages; exit 1 |

Exact commands (run in the existing provisioned Core worktree on branch `fix/object-lock-cleanup-finally`):

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/ObjectWriteLockTest.php --colors=never
lerd php vendor/bin/mago lint tests/Acceptance/RecordsAndArchives/ObjectWriteLockTest.php
lerd composer qa
lerd php vendor/bin/mago lint --reporting-format=rich
./bin/test.sh --colors=never
lerd composer analyse
```

The affected test and focused lint were run before and after the correction; the other final checks followed it. `composer qa` applies automatic lint fixes before analysis: its unrelated edits were captured and discarded, then full read-only lint, the full suite and separate analysis were run on the final one-file patch. See [integration-preparation/](integration-preparation/) for logs, exit statuses and patches; all raw logs and patches are gzip-compressed without changing their bytes. No static-analysis error was fixed or suppressed.

Lerd's watcher was stopped during preparation, so a newly requested worktree could not finish provisioning. It was removed cleanly and the already provisioned checkout was reused on separate branches. No application runtime failure was hidden by invoking Pest directly. The existing `.phpactor.json` was untouched. The verified #59 branch ref was never changed.

## Integration gate

At preparation time #59 still points to `3534a4c`, targeting #57's branch `test/recoverable-preservation-phase1`; #57 still points to `680476b`, targeting `dev`. All six #59 threads and all four #57 threads are resolved. Both PRs remain drafts, with no submitted GitHub approval decision and no status checks. Neither target has a branch protection rule or active ruleset; no rules or checks were waived. These are observations, not permission to merge.

**No merge is authorized in this preparation task.** The bounded behavior is independently verified, but repository-wide QA remains red and 33 existing contracts remain incomplete. The original Phase 2 head still contains the unsafe-finally test until the separate fix is integrated on dev. No #60 work was performed.

After explicit merge authorization and normal review/check gates, use this sequence:

1. Merge the separate QA fix #61 into `dev`; merge this evidence-only PR independently into `dev` so the evidence also reaches the integration branch.
2. Merge #59 into `test/recoverable-preservation-phase1` (#57's head), retaining the implementation rather than merging Phase 1 alone.
3. Recheck #57 at its new combined head and rerun the frozen/supplementary/full suites plus lint/QA on the combined candidate with current `dev` (which now includes #61). Classify any remaining failures; do not bypass required checks.
4. Only after that combined validation and explicit approval, merge #57 into `dev`.

The essential stacked order is **#59 → #57's branch → dev**. Updating #57 through step 2 changes its head and requires combined-head revalidation even though the frozen files must remain byte-identical. Any production/harness/contract change to #59 invalidates exact-head applicability of the original report and needs fresh relevant acceptance, physical and complete-suite verification. Documentation-only preservation leaves #59's verified commit unchanged.

Remaining analysis failures are baseline-wide and out of scope; the existing incomplete contracts are listed individually in the report. Projection isolation remains deferred to #60; no distributed fencing, cloud durability or device/power-loss claim is made.

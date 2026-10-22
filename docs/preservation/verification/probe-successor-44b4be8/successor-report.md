# Authorized TransactionProbe successor — review handoff

**Ready for independent acceptance review of the two authorized corrections. No merge is authorized or performed. Repository-wide QA remains FAIL.**

## Authorization and scope

Authority: [PR #63 review 5620898845](https://github.com/Lost-and-Fonds/stashd/pull/63#pullrequestreview-5620898845), followed by the explicit correction task. The reviewer authorized only the object-validation and failed-output-read corrections in `tests/Support/RecordsAndArchives/TransactionProbe.php`, outside-file regression tests and a separate acceptance-review step. Further protected-file defects are not preauthorized.

Base: current #57 integration branch `test/recoverable-preservation-phase1` at `d01cad7873ebd2d2f6cf643edc848135e47cacf9`.

Correction branch: `fix/transaction-probe-contracts`.

Tested code commit: `44b4be8a9db66bfbbeb6b8765aab790e5e7a2cc9`. This report is an append-only documentation/evidence addition after the tested code; runtime/test source remains identical to that commit. The PR targets the integration branch, not dev, and must remain unmerged for independent review.

Original frozen baseline remains immutable at `680476b57f87b5d356275074688c35eaadea4313`. This is an explicitly authorized **successor candidate**, not a claim that all five files still equal that baseline, and not a unilateral replacement of the frozen baseline. The original verification reports, native evidence archives and PR #63's counterexample source remain unchanged. No historical evidence was rewritten.

Only three runtime/test files differ from the integration base:

1. `tests/Support/RecordsAndArchives/TransactionProbe.php`: only `json()`, `finish()` and their PHPDoc.
2. `tests/Unit/TransactionProbeContractTest.php`: 25 focused cases and disposable-fixture helpers.
3. `tests/Support/RecordsAndArchives/transaction-probe-contract-process.php`: isolated real-child worker for warning/error-path tests.

All other additions are confined to this report/evidence directory. No production preservation code, dependencies, analyzer settings, acceptance expectations or existing incomplete contracts changed. [scope-audit.json](scope-audit.json) records the unchanged byte regions covering `start/paused/contend`, `run/committed` and `snapshot`; the helper diff contains no unrelated correction.

## Exact frozen-file exception

[contract-hashes.json](contract-hashes.json) records all five before/after Git blob IDs and SHA-256 digests. The sole changed protected file is TransactionProbe.php:

| Identity | Before | Successor |
|---|---|---|
| Git blob | `848cee541f8ef121c5b1ee43ad9c6d0c0de196c5` | `138fddfc653e36e63e0acc73a464b55baa8d7fa8` |
| SHA-256 | `e266e7d5941e7bab8809620d619a74312d2c4655af5d3c2c454e2ee3a17cf096` | `155ec9694615d1ce5c068c00ec04d0ba8f9a2ab5d74455443d99fb55d08b9030` |

The other four are byte-identical to 680476b and the integration base:

- RecoverableTransactionTest.php — blob `39489a759491a4aeb89b3c9922f05138dcd90514`.
- transaction-process.php — blob `5fd3d53baffaef65f40ba4a4e0cccf6917e7027e`.
- ProjectionProbe.php — blob `1039d0884aa252354b47fc6b12066d1be1becacf`.
- ProjectionProbeProofTest.php — blob `f146ce09eafdc519779e39b91346a0aba42f0749`.

## Correction behavior

`json()` still parses once using associative JSON decoding and JSON_THROW_ON_ERROR. Once parsing succeeds, checking the first non-whitespace root token distinguishes an object from a list, including `{}` versus `[]`, without changing nested object/list conversion. Top-level decoded keys must be strings; canonical numeric object keys that PHP converts to integers fail explicitly. Valid noncoercing numeric-looking keys remain accepted. A newly constructed string-keyed result makes the enforced contract truthful to callers and analysis, without a cast or fabricated type declaration. Scalar rejection, JSON decoding exceptions, valid nested arrays/objects and fractional metadata retain their existing behavior.

`finish()` still supervises/kills/reaps the child before reading logs. Both native file reads remain unsuppressed. Each result must be a string with a channel/path-specific diagnostic; failed reads never become empty strings and never escape as false. The existing timeout loop, timeout assertion, boundary observation/content checks, SIGKILL assertion, empty-success-output assertion and exit/signal extraction are otherwise unchanged. Readable empty logs are accepted. A nonzero child exit remains evidence returned by finish, not incorrectly recast as its own success failure; the existing caller success assertion in run is unchanged.

## Focused regression proof

The finalized tests were run first against the **exact original helper bytes**, then the successor was restored in `finally` and the identical tests rerun:

| Run | Result |
|---|---|
| Exact baseline helper | **8 failed, 17 passed, 145 assertions**, exit 1 |
| Successor helper | **25 passed, 183 assertions**, exit 0 |

The eight baseline failures are the five list/integer-coercing-key cases and three missing-log cases. The original `[1,2]` and failed-output-read counterexamples are now tests requiring explicit rejection, rather than tests that pass when the defects reproduce. The historical proof was not edited. Successful cases cover empty/whitespace objects, nested lists/objects/floats, noncoercing keys, retained scalar rejection/JSON errors, real child output, genuinely empty logs, nonzero exits, actual SIGKILL boundary evidence and missing-boundary rejection. The process-closed flag is checked after failed reads and normal/crash paths.

Native warnings run in a separate real PHP process and are asserted in captured stdout/stderr. No native error is suppressed, converted to an empty string or accepted as the intended explicit failure. The worker loads PHPUnit's serialized assertion-formatting configuration without installing PHPUnit's warning handler; it catches only AssertionFailedError as the intended rejection and fails the parent test for other worker failures.

During initial test construction the worker lacked this configuration. Those superseded runs had 9 failures/16 passes and 4 failures/21 passes, including worker bootstrap failures. Their complete logs are retained as regression-before/after.log.gz. After correcting only the new worker bootstrap, the controlled final baseline run had exactly the eight intended failures and the successor had 25 passes. The first formatting check also found one unformatted new test file; only the two new files were formatter targets, and the final full formatting check passed.

## Application, acceptance and QA verification

All application tests used the canonical wrapper, in the existing provisioned Lerd worktree at `/home/hazel/Projects/stashd/core/core-test-recoverable-preservation-phase1`. Runtime: PHP 8.5.11. Verifier: Codex assistant `/root` in this conversation/local runtime, not a separately identified human or external runner.

| Check | Result | Exit |
|---|---|---:|
| 25 regressions + unchanged Phase 1 + SQL witness | **74 passed, 10,958 assertions** (25 + 48 + 1), 21.51s | 0 |
| Supplementary transaction/custody/preparation/concurrency/journal/publication faults | **113 passed, 7,328 assertions**, 66.83s | 0 |
| Full application suite | **325 passed, 33 identical existing incomplete, 18,987 assertions**, 37.26s | 0 |
| Formatting | All files formatted | 0 |
| Full read-only lint | 0 errors, 4 existing warnings, 9 help messages | 0 |
| Analysis of the two new test files | No issues | 0 |
| Full analysis | **1,161 errors, 191 warnings, 11 help messages** | 1 |
| composer qa | Format/lint pass; fails at analysis with the same totals; test stage not reached | 1 |
| Independent physical OCFL/journal reader, fresh evidence | **1,097 PASS checks** | 0 |

The original 300 tests remain passing; the 25 additional passes are the new regressions. [junit-results.json](junit-results.json) compares every incomplete `(classname, name)` pair with the historical report: the **same 33**, with no newly incomplete, removed or reclassified cases. Their XML entries are skipped-shaped because that is PHPUnit's serialization of INCOMPLETE; console results explicitly retain their incomplete classification. Increased assertion totals in the existing suites include the newly enforced stdout/stderr string checks and scheduling-dependent supervisor assertions; no acceptance expectation was relaxed.

Exact commands (output/status artifacts use the corresponding descriptive filenames in this directory):

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Unit/TransactionProbeContractTest.php --colors=never
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Unit/TransactionProbeContractTest.php tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php tests/Support/RecordsAndArchives/ProjectionProbeProofTest.php --colors=never --log-junit=tmp/successor-phase1.xml
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives/TransactionEvidenceTest.php tests/Acceptance/RecordsAndArchives/TransactionCustodyTest.php tests/Acceptance/RecordsAndArchives/TransactionPreparationTest.php tests/Acceptance/RecordsAndArchives/CrashPublicationTest.php tests/Acceptance/RecordsAndArchives/PublicationReadConsistencyTest.php --colors=never --log-junit=tmp/successor-supplementary.xml
./bin/test.sh --colors=never --log-junit=tmp/successor-complete.xml
lerd php vendor/bin/mago fmt --check
lerd php vendor/bin/mago lint --reporting-format=rich
lerd php vendor/bin/mago analyse tests/Unit/TransactionProbeContractTest.php tests/Support/RecordsAndArchives/transaction-probe-contract-process.php
lerd composer analyse
lerd composer qa
```

Full analysis was compared by diagnostic severity/code/message/file against the historical integration log. [analysis-comparison.json](analysis-comparison.json) shows **no added signatures**, two removed errors (json and finish return contracts) and two removed false-operand warnings in finish. No broad analysis remediation, rule change, exclusion, baseline or suppression was introduced. Remaining helper defects in run/snapshot and other protected files were left alone, as required. The whole repository is not QA green and is not ready for a final merge on that basis.

`composer qa` generated seven unrelated automatic edits. They were recorded and all discarded, restoring sources exactly to the tested code commit; [qa-restoration.json](qa-restoration.json) and the compressed patch preserve that fact. No such edits were committed.

## Physical evidence and cleanup

The existing independent physical producer and numeric producer were copied temporarily from `docs/preservation/verification/pr59-3534a4c/` into ignored tmp paths and rerun using the successor helper. The existing Python reader checks raw bytes rather than application receipts. Commands:

```sh
lerd php tmp/successor-physical-producer.php
lerd php tmp/successor-numeric-producer.php
python3 docs/preservation/verification/pr59-3534a4c/read_physical_evidence.py tmp/pr59-independent-evidence
```

[physical-evidence.tar.gz](physical-evidence.tar.gz) retains the fresh disposable candidate/plan/completion/release journals, native OCFL inventories/sidecars/payloads, process boundaries and snapshots. The reader passed all 1,097 checks for actual crashes, source deletion then recovery, exact Item/Asset bindings, idempotent replay, interrupted custody release, lost acknowledgement and fractional metadata. Its source is unchanged historical verifier code; no production behavior or dependency changed. The archive contains only bounded fixture evidence, with no private credential fields detected.

Private PostgreSQL admin files were provisioned at the two documented fixture paths with mode 0600 using the existing local administrator; no credentials were printed or committed and no application env file changed. [postgres-before.txt](postgres-before.txt) and [postgres-after.txt](postgres-after.txt) report zero disposable databases/roles. [runtime-cleanup.txt](runtime-cleanup.txt) reports zero residual fixture credentials. The two private admin files, generated fixture Vault, serialized worker assertion configurations and temporary producers were removed; each regression fixture removes itself in finally. [cleanup.json](cleanup.json) records cleanup and exact tracked-source restoration. No real Vault was modified.

## Review gate

The successor is ready for **independent acceptance review of this narrow authorized exception**. It is not merged into #57 or dev, and #57 is not merged. The integration branch and original baseline/evidence remain unchanged remotely. No new baseline is declared by the implementer. Final acceptance, any further protected-file authorization, broad QA remediation, issue #58 closure and final integration remain separate decisions. #60 was not started.

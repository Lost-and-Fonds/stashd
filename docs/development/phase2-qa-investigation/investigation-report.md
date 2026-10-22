# Phase 2 QA investigation — blocked by frozen harness constraints

**Decision: FAIL / stop before merging.** Repository QA cannot be made genuinely green under the simultaneous requirements that all five frozen files remain byte-identical and that real analysis errors must be fixed rather than hidden. No implementation, test, dependency or analyzer setting was changed. No Phase 2 merge or issue closure is authorized by this report.

## Identities and actual runs

Investigated integration head: `d01cad7873ebd2d2f6cf643edc848135e47cacf9` (PR #57). Tested integration predecessor: `59ccd8d9d9161337329c27bcdcfd961d6cd41320`. Frozen baseline: `680476b57f87b5d356275074688c35eaadea4313`. Current dev remains `35a5c302e2f49f77a034dab1df46a78e3273d4fe`. PR #57 remains open; issue #58 remains open; #60 is untouched/open.

Verifier: OpenAI Codex assistant `/root`, this user conversation and the existing local Lerd/PHP runtime. Installed Mago: **1.57.0**. No independent human/external-runner or signed model identity is claimed. Working directory was `/home/hazel/Projects/stashd/core/core-test-recoverable-preservation-phase1` at d01cad7 for every execution below.

| Command | Actual result |
|---|---|
| `lerd composer analyse` | Exit 1: **1,163 errors, 193 warnings, 11 help messages** |
| `lerd php vendor/bin/mago analyse tests/Support/RecordsAndArchives/TransactionProbe.php --reporting-format=rich` | Exit 1: **11 errors, 3 warnings, 1 help message** in the frozen probe alone |
| `lerd php tmp/qa-frozen-contract-proof.php` | Exit 0: both asserted contract counterexamples reproduced; two expected unsuppressed file-read warnings retained |
| `lerd composer qa` | Format/lint stages pass; exits 1 at analysis with **1,163 errors, 193 warnings, 11 help messages**; test stage is not reached |

`composer qa` applies automatic lint fixes before analysis. All seven generated edits were captured and discarded; no automatic correction was committed. Exact source equality and frozen hashes were verified afterward. Before/after retained-source diagnostics are unchanged. No suppression, new baseline, exclusion, disabled rule or relaxed strictness was added. Existing `mixed-argument`/`mixed-assignment` ignores were observed in the baseline config and left unchanged, not expanded or relied upon as a fix.

The full application suite was not rerun because no application/test source changed and a genuine immutable-harness blocker was established. The **last verified** full result remains **300 passed, 33 existing incomplete, no failures**; it is historical evidence, not a fresh test result from this investigation. No incomplete case was edited or reclassified. The diagnostic below is an isolated helper self-check, not a replacement for the full suite.

## Diagnostic categorization

Counts are diagnostic locations, not independent defects. [categorization.json](categorization.json) contains counts by component, severity and code; [diagnostics.json.gz](diagnostics.json.gz) contains every message and location.

| Component | Errors | Warnings | Help |
|---|---:|---:|---:|
| Acceptance tests | 1,038 | 175 | 9 |
| Test support/harnesses | 60 | 17 | 1 |
| Tempest view template | 58 | 0 | 0 |
| Other production PHP | 7 | 1 | 1 |
| **Total** | **1,163** | **193** | **11** |

Root-cause groups, in the order useful for eventual repair:

1. **Pest closure binding and magic API metadata.** There are 147 `non-documented-property` warnings for fixture access, commonly `$this->archive` inferred on `Pest\PendingCalls\TestCall`. Runtime `tests/Pest.php` binds acceptance tests to `ArchiveTestCase`, which declares the fixture. Pest's vendor `@param-closure-this TestCall` metadata and dynamic extension model do not communicate the project-specific generated test class to this analyzer. Five `missing-magic-method` errors involve the documented `not` expectation property even though Pest's actual Expectation class has `__get`. Correct, faithful analyzer integration could eliminate large cascades, but must retain actual fixture scope and assertions rather than pretending every closure has every fixture.
2. **Unstructured fixture/JSON data.** 473 `mixed-method-access` plus 473 `mixed-array-access` errors span fixture, decoded JSON and template cascades. `ArchiveFixture::run` and ArchivesDriver return `array<string, mixed>` without operation-specific result shapes, so proving one container is an array does not prove nested objects, payloads or pins. OcflInspector and frozen Probe/Projection helpers have similarly broad input/output shapes. Truthful shared shapes/generics and boundary checks are preferable to hundreds of local casts or unverified annotations. The two 473 counts are diagnostic totals, not a claim they all have a single Pest-only cause.
3. **Fallible process, stream, file and PDO APIs.** Native calls may return false or optional outputs; resource pipes need presence/type checks, and PDO query/prepare types need mode-aware handling or explicit checks. This produces 30 invalid-method accesses, 24 possibly-null arguments, 19 possibly-false arguments, 19 possibly-invalid arguments and 20 optional-index warnings, among others. Some PDO diagnostics are likely precision issues because the fixture configures ERRMODE_EXCEPTION; absent output-file reads in the frozen helper are a confirmed real error-path/type-contract mismatch.
4. **Framework-provided template variables.** `app/VaultBrowser/browser.view.php` accounts for 58 errors. `$title`, `$data` and related values come from Tempest/controller view context but lack a local checked shape. This calls for faithful template context declarations/integration, not excluding view files. No rendering bug was established in this investigation.
5. **Production data/generic boundaries.** Seven non-template production errors are three unknown listing-item method calls in OcflFilesystemAdapter and four unstructured decoded JSON accesses in VaultIdentity. Accurate collection element generics and explicit identity-field validation are the likely local repair points. The latter already documents propagation of malformed/incompatible values. No new production preservation defect was reproduced or fixed here.
6. **Remaining generics, exhaustiveness and documentation precision.** Broad string datasets yield two non-exhaustive matches; associative-array key promises disagree with decoded JSON; by-reference process outputs lack declared shapes; fixture class-string validation does not prove a concrete instantiable class; unreachable code follows intentionally incomplete contracts. These require targeted corrections and scope-aware tooling. The 33 unfinished contracts must stay incomplete.

Not every warning is an actual runtime bug. The root-cause list distinguishes confirmed harness defects below from integration/type precision problems. No dependency upgrade or analyzer patch was committed speculatively.

## Confirmed blocker in a frozen file

File: [TransactionProbe.php at d01cad7](https://github.com/Lost-and-Fonds/stashd/blob/d01cad7873ebd2d2f6cf643edc848135e47cacf9/tests/Support/RecordsAndArchives/TransactionProbe.php).

### JSON key promise is not enforced

`TransactionProbe::json()` declares `@return array<string, mixed>` but accepts anything for which `Assert::assertIsArray()` succeeds. At **line 233**, returning `json_decode('[1,2]', true)` returns a list with integer keys. The runtime proof calls the **unchanged actual helper** and asserts keys `[0,1]`. It succeeds:

```json
{"json_helper":{"returned":[1,2],"keys":[0,1],"declared":"array<string, mixed>","matches_declared_key_type":false}}
```

This is not merely an analyzer failure to infer a true promise. The promise is false for an accepted input. The object-only prose and general PHP array assertion are different constraints. A truthful broader key type or enforced object/key validation requires editing the frozen file. Giving PHPUnit's general `assertIsArray()` a narrower string-key assertion would be demonstrably unsound.

### Fallible output reads escape a string-only return contract

`TransactionProbe::finish()` declares string `out` and `err`, but at **lines 148–149** assigns `file_get_contents()` directly, then returns the result at **line 158** without validating either read. A completed real child was launched through the unchanged supervisor; deleting only its disposable output log paths before finish caused both reads to return false. The method returned those false values. Both native warnings were retained, without an error handler, `@` or diagnostic suppression. The diagnostic self-check exits 0 only if both values are exactly false; its recorded summary is:

```json
{"finish_helper":{"out_type":"bool","err_type":"bool","declared_out_err":"string","matches_declared_value_types":false}}
```

This is an actual harness robustness/type-contract defect, not a demonstrated failure of accepted preservation publication/recovery. Missing diagnostic logs can cause the helper to violate its promised result shape. Correct handling must either reject failed reads or accurately expose a wider result; both require changes to the frozen helper. Declaring `file_get_contents()` or `hash_file()` infallible via analyzer metadata would conceal real failure paths.

The same file also has a legitimate possible-false hash result at **line 253**, loose child/request parameter shapes, and a decoded result key mismatch at **line 175**. It alone independently reports 11 errors, even without analyzing the consumer acceptance test files. The five frozen files contain **126 error locations and 32 warnings** in the full run; many are cascades that could be addressed elsewhere, but the demonstrated return-contract contradictions above are intrinsic to protected source.

## Reproduction and evidence

[qa-frozen-contract-proof.php](qa-frozen-contract-proof.php) is the exact temporary script used. Copy it into the ignored application `tmp/` directory at d01cad7 and run through Lerd, as recorded above. It loads the real frozen helper, uses a private disposable temporary directory and a real child, retains native warnings, and removes its generated fixture in `finally`. The copied script was removed from the checkout afterward. It is not an application/test source change or a weakened acceptance test.

The proof logs and full/focused analysis logs are preserved as gzip-compressed raw bytes. For example:

```sh
gzip -dc frozen-contract-proof.out.gz
gzip -dc frozen-contract-proof.err.gz
gzip -dc frozen-probe-analysis.log.gz
gzip -dc qa.log.gz
```

The current PHP configuration routed the native warnings into the stdout file; both stdout and stderr were retained, so an empty stderr must not be mistaken for suppression. No PostgreSQL role/database/admin config was created for this investigation; no real Vault was touched. The proof fixture was removed. The original Phase 2 and integration evidence was not rewritten. Frozen source hashes are recorded in [frozen-hashes.json](frozen-hashes.json).

## Required resolution before implementation can continue

The current requirements cannot both be met by correcting other files: frozen source contains demonstrably false declared return contracts, and hiding those errors would violate the no-suppression/no-weakened-analysis gate. Fixing most cascading diagnostics first would not remove this final blocker.

The acceptance owner must resolve the constraint: authorize a separately reviewed, narrowly scoped successor to the immutable harness for truthful types and checked I/O while preserving all acceptance expectations and original evidence, or identify a sound analyzer correction that still reports these demonstrated violations. A new frozen baseline would need independent acceptance re-verification; this task does not authorize creating one silently. Merely allowing generic fixture metadata improvements is insufficient for the intrinsic failures.

Until that is resolved, **do not merge #57, close #58, or call composer qa green**. No QA improvement PR was merged. This documentation-only draft report may be reviewed independently; it does not change either the dev or integration code. #60 remains open for the next phase and was not begun.

# Phase 2 integrated candidate — final verification

**Decision: PASS for the final merge decision on the bounded local Phase 2 contract. Repository-wide QA remains FAIL. PR #57 has not been merged into dev.**

All required execution groups passed, all five frozen contracts are byte-identical, and there are no new test failures, incomplete cases, static-analysis diagnostic signatures, conflicts or required-check blockers. This is a recommendation for the user's separate final merge decision, not permission to merge, an unqualified repository-wide QA pass, or certification of distributed/power-loss durability. The acknowledged 33 incomplete contracts and non-green repository-wide analysis remain real limitations.

## Exact identities and history

| Identity | Commit |
|---|---|
| Integration branch / PR #57 head tested | `test/recoverable-preservation-phase1` at `59ccd8d9d9161337329c27bcdcfd961d6cd41320` |
| Independently verified implementation / PR #59 head | `3534a4c37aa77f53d7f331d97808eb05859b1e00` |
| Frozen Phase 1 baseline | `680476b57f87b5d356275074688c35eaadea4313` |
| PR #59 merge into the Phase 1 branch | `de9810d16fca960985c54f6e2f13c752ff043196` |
| Current dev merged into the integration branch | `35a5c302e2f49f77a034dab1df46a78e3273d4fe` |
| PR #61 merge in dev | `620fb0cd4abd7c8f6a1052ba090b8405ed2b82a3` |
| PR #62 evidence commit / merge | `0c9442294a25a0c6eba1d11cd8e79920b5bb669b` / `35a5c302e2f49f77a034dab1df46a78e3273d4fe` |

The #59 merge has parents **680476b, 3534a4c**, and its tree equals the reviewed implementation tree. The dev-reconciliation merge has parents **de9810d, 35a5c30**. No squash, rewrite or amendment was used. The merge was conflict-free and matched a separately computed `git merge-tree --write-tree` result. No manual conflict resolutions were needed.

Compared with 3534a4c, the tested candidate changes exactly **67 files**: the reviewed ObjectWriteLockTest cleanup correction and 66 preservation-evidence documentation/artifact files. Production code and dependency lock are unchanged; no migration change or real application-database reconfiguration was needed. No suppression or test weakening was introduced. Issue #60 was not begun.

This report is added in a subsequent **documentation-only commit** on that integration branch. The tested execution identity remains 59ccd8d; the published final PR head includes this report and evidence. Before publication, production/test/config/runtime files must be verified unchanged from 59ccd8d, all frozen hashes rechecked, and the remote final PR head recorded in the user-facing handoff. No repeat runtime claims are attached to untested source changes.

## Starting-state and integration checks

Fresh GitHub reads confirmed #61/#62 merged and ancestral to dev, #59 at exactly 3534a4c with its four reviewed commits, #57 at exactly 680476b with its three baseline commits, the preserved original report's SHA-256, all six #59 and four #57 review threads resolved, no active rulesets/branch rules, and no status checks. The first strict check encountered transient GitHub mergeability recalculation; a fresh read confirmed CLEAN/MERGEABLE. There was no changed source or review gate. See [starting-state.json](starting-state.json).

#59 was marked ready, then merged with an expected-head SHA guard and normal merge method. GitHub confirms it actually merged at de9810d. Current dev was merged normally into the combined branch and the candidate pushed. #57 remains open and unmerged; it can be marked ready for final review after this report is published. [Post-verification state](post-verification-remote-state.json) confirms dev remained 35a5c30, #57 was CLEAN/MERGEABLE, and no new required checks appeared. A GitHub mergeability value on an already merged #59 is not an outstanding gate.

## Frozen files

Each current working-tree Git blob equals `680476b:<path>` and its recorded SHA-256 below. None changed during integration. [candidate.json](candidate.json) includes both the full Git blob identifiers and SHA-256 hashes.

| Frozen path | SHA-256 |
|---|---|
| `tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php` | `b136220b00096822c0c8ab030a2bbad2f1850c855efc3f1688802a505d8133fb` |
| `tests/Support/RecordsAndArchives/transaction-process.php` | `3b0760efbaaec6b3e56c8a29f3b1c2f9cf15599b85210fd97b6299efff9ec0eb` |
| `tests/Support/RecordsAndArchives/TransactionProbe.php` | `e266e7d5941e7bab8809620d619a74312d2c4655af5d3c2c454e2ee3a17cf096` |
| `tests/Support/RecordsAndArchives/ProjectionProbe.php` | `de8d1e709c34cd3b324c82430e8d9803b4b1cf3d9d7e69e461781b59edb8ad3a` |
| `tests/Support/RecordsAndArchives/ProjectionProbeProofTest.php` | `f94417dc6e6db6dd79dbc0be001e288613d8b1a52102026d26fd68a0c2b941b5` |

## Execution and results

Verifier: **OpenAI Codex assistant `/root` in this conversation**, using the same local machine/Lerd runtime, PHP **8.5.11** and local PostgreSQL. No distinct human identity, external runner, signed attestation or separately identified model/run is claimed. These are fresh executions on the integrated candidate, not copied test results from the original verification. Work directory:

`/home/hazel/Projects/stashd/core/core-test-recoverable-preservation-phase1`

| Check | Actual result | Exit |
|---|---|---:|
| Frozen Phase 1, including three projection-loss crash positions | **48 passed, 7,605 assertions**, 20.11s | 0 |
| Supplementary preservation/journal/custody/preparation/concurrency + publication fault tests | **113 passed, 5,735 assertions**, 67.52s | 0 |
| Independent PostgreSQL witness negative controls | **1 passed, 83 assertions**, 0.32s | 0 |
| Complete suite, four processes | **300 passed, 33 incomplete, 14,125 assertions**, 37.13s | 0 |
| Formatting, read-only `mago fmt --check` | All files already formatted | 0 |
| Full read-only lint | **0 errors, 4 warnings, 9 help messages** | 0 |
| Full standalone static analysis | **1,163 errors, 193 warnings, 11 help messages** | 1 |
| Required `composer qa` | Format/lint pass; fails at analysis with same totals; test stage not reached | 1 |
| Fresh independent physical OCFL/journal reader | **1,097 checks passed**, nine snapshots | 0 |

Raw log bytes and QA's automatic-edit patch are losslessly gzip-compressed. Exit files and JUnit XML are uncompressed. To view a log, for example: `gzip -dc complete.log.gz`. All exact incomplete names and their baseline comparison are in [junit-results.json](junit-results.json); the original human-readable list remains in [the preserved baseline report](../pr59-3534a4c/verification-report.md).

Commands actually run:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php \
 --colors=never --log-junit=tmp/final-phase1.xml

STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/TransactionEvidenceTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionCustodyTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionPreparationTest.php \
 tests/Acceptance/RecordsAndArchives/CrashPublicationTest.php \
 tests/Acceptance/RecordsAndArchives/PublicationReadConsistencyTest.php \
 --colors=never --log-junit=tmp/final-supplementary.xml

STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Support/RecordsAndArchives/ProjectionProbeProofTest.php \
 --colors=never --log-junit=tmp/final-projection.xml

./bin/test.sh --colors=never --log-junit=tmp/final-complete.xml

lerd php vendor/bin/mago fmt --check
lerd php vendor/bin/mago lint --reporting-format=rich
lerd composer analyse
lerd composer qa
```

Each execution's stdout/stderr was retained as `<label>.log.gz` and status as `<label>.exit`; labels are phase1, supplementary, projection, complete, format, lint, analyse and qa. [The JUnit summary](junit-results.json) independently confirms the case counts and zero failures/errors. The full suite reports no separate warning, risky-test or deprecation category.

`composer qa` automatically applies unrelated lint fixes before analysis. Its seven generated edits were recorded, then all discarded. No such edit was committed. [qa-restoration.json](qa-restoration.json) lists them, and [qa-automatic-edits.patch.gz](qa-automatic-edits.patch.gz) preserves the exact tool-generated patch. None of the five protected files was among them. Tracked files were then verified identical to the tested candidate, so the independent read-only lint/format/analysis and test results still describe the retained source.

## Comparison with established baselines

Original independent verification at 3534a4c had 48 frozen passes, 113 supplementary passes, 300 full-suite passes and 33 incompletes. The integrated counts are identical. Original assertions were 7,607 / 5,738 / 14,121 respectively; the fresh run has 7,605 / 5,735 / 14,125. Polling supervisors assert inside scheduling-dependent loops, so assertion counts vary; no case disappeared or became incomplete.

The **same 33 incomplete case identities**, compared as exact `(classname, test name)` pairs, remain incomplete; there are **zero newly incomplete or removed incomplete cases**. JUnit serializes these as `<skipped/>`, while the console explicitly reports INCOMPLETE. They were not counted as passes. Their seven source files remain unchanged. The groups are MetadataHistory (5), ReplicaRecovery (8), StoragePortability (2), CommitConsistency (6), PreservationEvidence (5), FixityAndRepair (6), VaultIdentity (1). These concern unimplemented metadata/event provenance, replica/fixity/repair and older generic driver operations, not newly suppressed Phase 2 acceptance failures.

The former unsafe-finally lint error is gone due to #61. Four prior lint warnings remain: explicit-octal at ArchiveFixture.php:33/:58 and VaultIdentityTest.php:64, and no-multi-assignments at ObjectWriteLockTest.php:49. The nine help messages are the existing naming and early-continue suggestions, all visible in [lint.log.gz](lint.log.gz).

Static analysis is **not green**. Beyond matching counts, the full preserved 3534a4c diagnostics were compared as a multiset of **severity, code, first-line message and file**. All **1,367 diagnostic signatures match**, with zero additions or removals; line/column positions are ignored because the cleanup edit shifts later locations. See [analysis-comparison.json](analysis-comparison.json). This is stronger than comparing totals but does not claim semantic equivalence of every analyzer trace. No failure was hidden, suppressed or fixed. The dev-only 1,037-error report was a different source baseline; its lower number must not be presented as the Phase 2 result.

## Fresh physical preservation/recovery evidence

Fresh disposable Vaults were generated from the candidate. The preserved diagnostic producers only invoke the real production boundary and supervise actual fresh PHP processes/SIGKILL. The reader uses Python's standard library and imports neither Stashd nor the existing PHP OCFL test oracle. Its content/provenance hashes are in [physical-tool-provenance.json](physical-tool-provenance.json). These scripts reuse historical transaction-ID fixture strings containing `3534a4c`; that label does not make the data historical. The Vaults, journal bytes, process events and receipts in this archive were all freshly produced on **59ccd8d**.

```sh
cp docs/preservation/verification/pr59-3534a4c/physical-producer.php \
 tmp/final-physical-producer.php
cp docs/preservation/verification/pr59-3534a4c/numeric-producer.php \
 tmp/final-numeric-producer.php
lerd php tmp/final-physical-producer.php
lerd php tmp/final-numeric-producer.php
python3 docs/preservation/verification/pr59-3534a4c/read_physical_evidence.py \
 tmp/pr59-independent-evidence
```

[physical-reader.log.gz](physical-reader.log.gz) reports **1,097 PASS checks**. [fresh-physical-evidence.tar.gz](fresh-physical-evidence.tar.gz) retains 477 small fixture/evidence files, including all nine stage snapshots, actual process boundary/signal evidence, raw candidate/plan/completion/release journals, payloads and independently computed file hashes. It contains no database dump, private fixture credentials, application env, dependency tree or symlink. The archived empty lock files and process observations are historical local-lock evidence, not installed runtime artifacts.

The raw reader verified:

- Actual signal 9 and no success reply after the first Asset: one Asset Object, no Item/sidecar Object or completion, both custody copies still present. After producer sources were removed, fresh-process recovery produced exactly one v1 Item and two v1 Assets, reusing the first acknowledged Asset.
- Candidate seals and Vault identities; SHA-512 of each envelope's **original JSON bytes**; exact candidate/plan bindings in physical Item and Asset versions; completion and release linkages; accepted payload digests/sizes; exact historical pins and Item metadata.
- Every stage's OCFL markers, unique IDs, contiguous history/head, root/latest-version inventory equality, root and historical sidecars, historical state consistency, safe manifest paths and every manifest payload digest.
- Identical receipts and every Vault file byte unchanged on repeat commit/recover. Interrupted release deleted only the first redundant custody copy, retained authorization and the second copy, and recovered/replayed idempotently. Complete release removed only the two accepted redundant copies and added authorization, preserving all permanent bytes.
- Lost acknowledgement after persisted completion replayed without any Vault mutation; a sidecar update advanced Item/sidecar once while retaining the master v1 pin and old history. Native float 1.0/nested 2.0/integer 0 retained their types and both Item/Asset bindings, followed by unchanged fresh-process replay.

To inspect or rerun without touching an application Vault, extract into a disposable directory and pass its path to the preserved reader:

```sh
mkdir /tmp/stashd-final-physical-check
 tar -xzf fresh-physical-evidence.tar.gz -C /tmp/stashd-final-physical-check
python3 ../pr59-3534a4c/read_physical_evidence.py \
 /tmp/stashd-final-physical-check/fresh-physical-evidence
```

This is a bounded physical smoke check, not complete OCFL standards conformance validation. Existing adversarial oracle negative controls executed in the suites; no fresh production-source mutation was needed or committed for this merge-only integration.

## PostgreSQL and credential cleanup

The unchanged fixture was provisioned with the required two private ignored JSON files, `tmp/transaction-projection-admin.json` and `tmp/projection-proof.json`, both mode **0600**. They used only the confirmed existing local PostgreSQL administrator and local DSN `pgsql:host=lerd-postgres;port=5432;dbname=postgres`. The existing container password was read privately from `POSTGRES_PASSWORD`, written directly to the files and never printed. No application `.env` or server credentials were changed; no additional admin role was created. Frozen fixtures generate strictly named unprivileged roles/databases/schemas and verify identity/privileges/ownership before destructive operations. Preservation process probes retain their deliberately unreachable ordinary application DB settings.

Before and after SQL audit:

```sh
podman exec lerd-postgres psql -U postgres -d postgres -At -c \
 "SELECT 'disposable_databases=' || count(*) FROM pg_database WHERE datname ~ '^stashd_tx_test_' UNION ALL SELECT 'disposable_roles=' || count(*) FROM pg_roles WHERE rolname ~ '^stashd_tx_test_';"
```

Both [before](postgres-before.txt) and [after](postgres-after.txt) report **0 databases and 0 roles**. Both private config files were unlinked after test completion; diagnostic producer files, their disposable working Vault and ignored JUnit files were removed after retaining non-secret evidence. A scan of PR/main tmp directories found no admin JSON or fixture credential descriptors. The container scan command was:

```sh
lerd php -r 'echo "php=" . PHP_VERSION . PHP_EOL . "tmp=" . sys_get_temp_dir() . PHP_EOL; $paths=glob(sys_get_temp_dir() . "/stashd-archives-*/projection-stashd_tx_test_*.json"); echo "residual_fixture_credentials=" . count($paths) . PHP_EOL; foreach ($paths as $path) { echo $path . PHP_EOL; }'
```

[runtime-cleanup.txt](runtime-cleanup.txt) reports **0 residual fixture credentials**. [cleanup-files.json](cleanup-files.json) records removal, exact tracked-source restoration, unchanged pre-existing `.phpactor.json`, and the fresh archive's credential/secret-pattern checks. No private config bytes were retained in these artifacts.

## Final limitations and merge gate

**No new regression or required-check blocker was found.** The integrated local Phase 2 contract passes the established acceptance criteria. All failure logs, 33 incomplete case identities and unchanged static-analysis failures remain disclosed. The branch is suitable for the final merge decision within that bounded scope; repository-wide QA is still FAIL and must never be represented as green.

No #57-to-dev merge was performed. Any new production, harness, contract, runtime configuration or dependency change after the tested candidate requires relevant fresh verification. A documentation-only report commit is explicitly distinguished from the tested execution commit. Recheck current dev and branch protection/status gates immediately before the future final merge.

Unverified/deferred: independent external machine/human certification, distributed fencing, cloud backends, fsync/device/power-loss durability, automatic ambiguous intra-Object repair, removal semantics and production catalogue schema adoption. All-or-nothing projection failure isolation remains the explicit #60 limitation and was not implemented here.

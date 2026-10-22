**Independent verification of Lost-and-Fonds/stashd PR #59**

Verified commit: `3534a4c37aa77f53d7f331d97808eb05859b1e00`.
Frozen baseline: `680476b57f87b5d356275074688c35eaadea4313`.
Run date: 2026-10-22, Pacific/Auckland. Runtime: local Lerd/Podman, PHP 8.5.11, local PostgreSQL.
Working directory for commands below: `/home/hazel/Projects/stashd/core/core-test-recoverable-preservation-phase1`.

**Decision: PASS for the bounded local Phase 2 transaction contract at this commit. Repository-wide QA: FAIL.**

All 48 frozen acceptance cases and 113 supplementary/publication cases passed; the full suite had 300 passed and 33 existing incomplete cases. Independent raw-file verification passed 1,097 checks. No application/test/acceptance source was changed. No merge, branch deletion, GitHub comment, approval, or workspace submodule update was performed. GitHub still reports the PR open and draft at the same head, with no CI workflows for that head. This is executed local verification, not an unqualified repository-wide green sign-off or a distributed/power-loss durability certification.

**Commands and results**

Commands are reproduced below; complete output and exit status files are retained alongside this report. Runtime commands used Lerd and application tests used the unchanged canonical wrapper.

```sh
# Frozen files and working-tree integrity
 git rev-parse HEAD
 git diff --exit-code 3534a4c -- .
 git diff --exit-code 680476b 3534a4c -- \
  tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php \
  tests/Support/RecordsAndArchives/transaction-process.php \
  tests/Support/RecordsAndArchives/TransactionProbe.php \
  tests/Support/RecordsAndArchives/ProjectionProbe.php \
  tests/Support/RecordsAndArchives/ProjectionProbeProofTest.php
```

All return exit 0 with empty diffs. Each frozen file's working-tree Git blob hash independently equals its baseline blob hash; see [frozen-files.txt](frozen-files.txt). This includes the actual process harness and independent PostgreSQL/OCFL probes, not only the acceptance test file.

| Verification | Result | Exit | Evidence |
|---|---|---:|---|
| Frozen Phase 1 | 48 passed; 7,607 assertions; 19.85s | 0 | [phase1.log](phase1.log) |
| Supplementary transaction/custody/preparation plus publication faults | 113 passed; 5,738 assertions; 67.28s | 0 | [supplementary.log](supplementary.log) |
| Standalone PostgreSQL oracle negative controls | 1 passed; 83 assertions; 0.31s | 0 | [projection-proof.log](projection-proof.log) |
| Complete suite, 4 processes | 300 passed; 33 incomplete; 14,121 assertions; 37.16s | 0 | [complete.log](complete.log) |
| Independent physical reader | 1,097 checks passed, nine stage snapshots | 0 | [physical-reader.log](physical-reader.log) |
| Required composer QA | Formatting passed; lint blocked subsequent QA stages | 1 | [qa.log](qa.log) |
| Separate full static analysis | 1,163 errors; 193 warnings; 11 help messages | 1 | [analyse.log](analyse.log) |
| All changed PHP files, separate analysis | 0 errors; 18 warnings; 1 help message | 0 | [changed-php-analyse.log](changed-php-analyse.log) |

The full-suite assertion count differs from the PR's reported 14,116; this run produced 14,121. The concurrency supervisors assert inside polling loops, so assertion counts can vary with scheduling. The case counts are identical; this report records the actual observed count.

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/RecoverableTransactionTest.php --colors=never

STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/TransactionEvidenceTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionCustodyTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionPreparationTest.php \
 tests/Acceptance/RecordsAndArchives/CrashPublicationTest.php \
 tests/Acceptance/RecordsAndArchives/PublicationReadConsistencyTest.php --colors=never

STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Support/RecordsAndArchives/ProjectionProbeProofTest.php --colors=never

./bin/test.sh --colors=never

# Executed before the no-modifications verification request; confirmed no tracked changes afterward.
lerd composer qa > /tmp/stashd-pr59-qa.log 2>&1
# Its complete log was copied here as qa.log.
lerd composer analyse

lerd php vendor/bin/mago analyse \
 app/RecordsAndArchives/ArchiveProjection.php \
 app/RecordsAndArchives/AssetArchive.php \
 app/RecordsAndArchives/ItemArchive.php \
 app/RecordsAndArchives/PreservationTransaction.php \
 app/RecordsAndArchives/Storage/Providers/LocalArchiveStorage.php \
 app/RecordsAndArchives/Vault.php \
 tests/Acceptance/RecordsAndArchives/TransactionCustodyTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionEvidenceTest.php \
 tests/Acceptance/RecordsAndArchives/TransactionPreparationTest.php \
 tests/Pest.php tests/Support/RecordsAndArchives/PreparationProbe.php \
 tests/Support/RecordsAndArchives/TransactionProjectionFixture.php \
 tests/Support/RecordsAndArchives/transaction-adapter.php
```

The 113-case selection explicitly covers all three new supplementary test files, existing intra-Object publication crash tests, and read-consistency races. The complete suite additionally runs existing Object-lock and other concurrency coverage. Faults include actual child SIGKILL at Asset/Item publication boundaries, acknowledgement loss, malformed/missing/rechecksummed evidence, same-ID preparation collisions, owner death, stale sealing conflicts, unrelated progress during a paused custody copy, release interruptions, forged completion, and damaged-bucket/healthy-bucket discovery isolation. No production mutation controls were run because source modification was explicitly prohibited. Existing oracle negative controls did execute, including false receipts, lost relationships, corrupt payloads, duplicate-publication witnesses, lock bypass detection, and incorrect/missing persisted PostgreSQL rows.

**Initial failures and environment prerequisites**

The interrupted pre-request complete run ended with **3 failed, 297 passed, 33 incomplete, 13,821 assertions, exit 1**. All three failures were PSC-T09: `An explicit private local projection test-admin configuration is required.` That run's output was recovered from its session; a standalone complete logfile was not retained, so it is not represented as a separate log artifact here.

A subsequent explicit frozen-suite plus SQL-proof run, while setup was still incomplete, ended with **4 failed, 45 passed, 7,302 assertions, exit 1**: the same three missing-admin-config failures and one missing `tmp/projection-proof.json` failure. Its unabridged output is [phase1-unconfigured.log](phase1-unconfigured.log). The first setup attempt stopped because the PostgreSQL container has no explicit `POSTGRES_USER` environment variable; no config was created by that attempt. A trial local psql connection with username `root` failed because that role does not exist. The correct existing local administrator is `postgres`.

Provisioning the two private ignored JSON files required by the unchanged fixture resolved the prerequisite failures. Both were created with mode 0600 using the existing local PostgreSQL administrator and the password read privately from the local container's `POSTGRES_PASSWORD`; no password was printed or put in application env files. No database authentication was weakened, no test was altered, and no extra admin role was created. Both files were removed after the final tests. The frozen SQL-proof test requires its own `tmp/projection-proof.json`, separate from `tmp/transaction-projection-admin.json`.

The first sandboxed wrapper invocation was denied access to the user DBus socket and exited before running tests. The canonical wrapper then ran successfully with access to the already configured Lerd/Podman runtime. These were environment/setup failures, not passing tests or repaired product defects.

**Independent physical publication and journal evidence**

A temporary diagnostic producer called the existing real Core transaction boundary and used the unchanged frozen process supervisor solely to launch/reap fresh PHP children and perform real SIGKILL. Its source is retained as [physical-producer.php](physical-producer.php); the additional direct native-float producer is [numeric-producer.php](numeric-producer.php). Neither is an application/test source change. The independent reader is [read_physical_evidence.py](read_physical_evidence.py); it imports no Stashd or existing OCFL test-reader code.

Executed commands:

```sh
# Temporary diagnostics were in the ignored tmp directory, then removed.
lerd php tmp/pr59-physical-verifier.php
lerd php tmp/pr59-numeric-verifier.php
python3 /tmp/stashd-pr59-verification-3534a4c/read_physical_evidence.py \
 tmp/pr59-independent-evidence
# After evidence relocation the reader can also be rerun with:
python3 /tmp/stashd-pr59-verification-3534a4c/read_physical_evidence.py \
 /tmp/stashd-pr59-verification-3534a4c/physical-evidence
```

Every stage's actual Vault bytes, process requests/results, observed boundary events, and independently generated SHA-512 file manifests are retained under [physical-evidence](physical-evidence/). The detailed 1,097 checks are [physical-checks.txt](physical-evidence/physical-checks.txt), with a machine-readable [summary](physical-evidence/physical-verification.json).

| Scenario/stage | Independently observed result |
|---|---|
| SIGKILL after master Asset verification | Child signal 9, no success output; exactly one physical Asset Object; no Item, sidecar Object, or completion journal; both custody copies retained. |
| Recover after producer source files are deleted | Exactly one Item and two Asset Objects, each v1. Previously published master remains v1; both accepted payload digests/sizes match the sealed candidate. |
| Repeat commit | Identical completion receipt and byte-for-byte identical entire Vault snapshot. |
| SIGKILL during release after master custody deletion | Signal 9, no success output; master custody absent, sidecar custody still present; retained release authorization binds exact completion. Fresh recovery returns the same receipt. |
| Complete release and repeat commit/recovery | Only custody-0/custody-1 removed and custody-release.json added; every permanent journal/OCFL byte unchanged. Subsequent commit/recover makes no Vault change. |
| SIGKILL after completion but before success reaches caller | Signal 9, empty output; exact completion evidence already present. Recovery and repeat commit produce the same receipt and no Vault byte changes. |
| Changed sidecar | Item v2 and sidecar v2; unchanged master remains pinned to v1. Historical v1 Item and Asset bindings remain intact. |
| Native fractional metadata | Direct request preserves float 1.0, nested float 2.0, and integer 0; raw physical plan JSON hashes match both Item and Asset transaction bindings. Fresh-process replay is unchanged. |

For every physical Object at every stage, the reader verifies OCFL 1.1 markers, unique identity, contiguous versions/head, exact root/latest-version inventory equality, raw root and historical inventory checksum sidecars, historical state consistency, manifest containment and every manifest payload digest. It resolves exact logical payloads through native inventory state/manifest mappings. For each transaction it independently hashes candidate bytes and the original plan-envelope JSON string, validates completion/release envelopes and candidate/Vault linkage, then compares physical Item and Asset transaction fields to those digests. It verifies exact pins and Item metadata against the plan, rather than trusting the returned receipt alone.

The frozen supervisor normalizes whole-valued float request JSON before Core receives it. Therefore the additional native-float case invokes the production adapter directly for initial execution; its replay runs in a fresh supervised process. This avoids falsely claiming that the ordinary process request preserved 1.0. These checks are a bounded physical smoke verification, not a complete OCFL conformance validator.

**Incomplete cases**

The 33 incomplete cases were not counted as passes. Their seven source files are unchanged from the frozen baseline; see [incomplete-files-baseline.txt](incomplete-files-baseline.txt). The full suite reports no separate test warnings, deprecations, or risky-test category. JUnit represents incomplete tests with `<skipped/>`; the console logs identify them explicitly as INCOMPLETE.

| File | Incomplete count |
|---|---:|
| `CommitConsistencyTest.php` | 6 |
| `FixityAndRepairTest.php` | 6 |
| `MetadataHistoryTest.php` | 5 |
| `PreservationEvidenceTest.php` | 5 |
| `ReplicaRecoveryTest.php` | 8 |
| `StoragePortabilityTest.php` | 2 |
| `VaultIdentityTest.php` | 1 |

The complete run's exact reason frequencies are: replica synchronization/recovery unimplemented (8); metadata.record unimplemented (5); fault.interrupt unimplemented (5); asset.describe unimplemented (4); fixity.verify unimplemented (3); replica.sync unimplemented (3); catalogue.list unimplemented (1); acquisition provenance events unimplemented (1); storage.probe unimplemented (1); catalogue.drop_projection unimplemented (1); concurrency.preserve unimplemented (1).

All individual case names follow; machine-readable copies are [incomplete-cases.json](incomplete-cases.json), [pr59-incomplete.xml](pr59-incomplete.xml), and [pr59-incomplete-extra.xml](pr59-incomplete-extra.xml).

**CommitConsistencyTest.php**

- an interrupted staged write cannot become a falsely committed Asset
- a crash after a durable Asset commit but before its Item commit leaves no invented Item pin
- an interrupted inventory publication reconciles instead of claiming success
- two competing writers cannot corrupt or silently overwrite committed Asset history
- source mutation between digesting and publication cannot create a false fixity receipt
- an interrupted operation cannot leave a complete Item that references missing bytes

**FixityAndRepairTest.php**

- fixity verification actually reads committed content and reports a healthy version
- external corruption of canonical bytes cannot be reported as verified
- corrupt inventory checksums invalidate object verification even if payload survives
- verified replica repair restores exact historical bytes and records repair history
- a corrupted replica is never accepted as repair authority
- a repair cannot silently replace the archived original with different content

**MetadataHistoryTest.php**

- source-provided metadata remains recoverable as an immutable document revision
- updating one metadata stream retains the original version
- different sources for the same metadata facet keep separate provenance identities
- editorial corrections cannot overwrite the original source observation
- invalid metadata must not replace a previously preserved valid revision

**PreservationEvidenceTest.php**

- an open expected-component set does not justify claiming complete preservation
- a closed known scope with verified components may be reported complete
- a partial Asset with missing known components remains preserved but incomplete
- later complete acquisition cannot retroactively declare an older partial version complete
- reacquiring identical source bytes still preserves new provenance evidence

**ReplicaRecoveryTest.php**

- a replica of a complete Vault has the same identity and a verified coherent recovery point
- clean-room recovery retains the original Vault identity without the old primary or database
- a recovered Item still pins the old exact Asset revision after later revisions existed
- rebuilding the catalogue from an independently verified replica retains metadata-only Items
- a lagging replica cannot claim to contain changes newer than its own verified recovery point
- a corrupted replica cannot be declared a complete recoverable Vault
- a restore never writes archival repairs or new authority into its source replica
- a complete restore preserves archival event evidence rather than rebuilding only current media

**StoragePortabilityTest.php**

- the actual configured backend, not the logical Vault, reports storage capabilities
- physical storage integrity is independent of the availability of catalogue projections

**VaultIdentityTest.php**

- an empty new Vault has no archived Items or Assets

Exact supplementary commands used to obtain complete names, since the parallel console truncates them:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/MetadataHistoryTest.php \
 tests/Acceptance/RecordsAndArchives/ReplicaRecoveryTest.php \
 tests/Acceptance/RecordsAndArchives/StoragePortabilityTest.php \
 tests/Acceptance/RecordsAndArchives/CommitConsistencyTest.php \
 tests/Acceptance/RecordsAndArchives/PreservationEvidenceTest.php \
 --colors=never --log-junit=tmp/pr59-incomplete.xml
# 26 incomplete, 3 passed, 13 assertions, exit 0.
STASHD_TEST_PROCESSES=1 ./bin/test.sh \
 tests/Acceptance/RecordsAndArchives/FixityAndRepairTest.php \
 tests/Acceptance/RecordsAndArchives/VaultIdentityTest.php \
 --colors=never --log-junit=tmp/pr59-incomplete-extra.xml
# 7 incomplete, 7 passed, 15 assertions, exit 0.
```

**QA blockers and warnings**

`composer qa` is **not green**. Formatting reported all files already formatted. Lint found one error, four warnings and nine help messages, then stopped; its automatic-fix stage was not reached and no tracked file changed. The blocker is `no-unsafe-finally` at `tests/Acceptance/RecordsAndArchives/ObjectWriteLockTest.php:86`, where `break` occurs inside `finally`. The entire file is unchanged from 680476b. QA consequently did not execute its later analysis/test stages; those were run separately as recorded above.

Lint warnings: explicit-octal at ArchiveFixture.php:33 and :58 and VaultIdentityTest.php:64; no-multi-assignments at ObjectWriteLockTest.php:49. Help messages: prefer-early-continue at ReplicaRecoveryTest.php:216/:234, TransactionEvidenceTest.php:128/:197, PreparationProbe.php:39, CrashPublicationTest.php:58/:85, PublicationInspection.php:50; function-name at ObjectWriteLockTest.php:14. Full diagnostics and suggestions are retained in qa.log; none was silently fixed.

Repository-wide analysis independently reproduced the documented head totals: **1,163 errors, 193 warnings, 11 help messages**, exit 1. All diagnostics with exact locations are in analyse.log, and counts grouped by diagnostic code are in [analysis-diagnostic-counts.json](analysis-diagnostic-counts.json). This run did not execute static analysis on a separate baseline checkout, so it does not certify that each individual diagnostic predates the PR. The totals match the existing report; the lint blocker and incomplete-source files were independently confirmed unchanged.

Analysis of **all 13 changed PHP files** returned **zero errors, 18 warnings and one help message**. All 18 warnings are `non-documented-property` for Pest's dynamic `$this->archive` fixture access: eight in TransactionCustodyTest, seven in TransactionEvidenceTest, three in TransactionPreparationTest. The help message is `redundant-type-comparison` at PreservationTransaction.php:709. This broader changed-file analysis does not reproduce the PR body's narrower seven-warning claim; the actual observed total here is 18. Complete diagnostics are in changed-php-analyse.log.

**PostgreSQL, credentials and source cleanup**

Pre-run inspection found zero generated transaction-test databases and roles. The final audit, performed after the full and diagnostic test runs, reports:

```text
disposable_databases=0
disposable_roles=0
residual_fixture_credential_descriptors=0
```

Commands:

```sh
podman exec lerd-postgres psql -U postgres -d postgres -At -c \
 "SELECT 'disposable_databases=' || count(*) FROM pg_database WHERE datname ~ '^stashd_tx_test_' UNION ALL SELECT 'disposable_roles=' || count(*) FROM pg_roles WHERE rolname ~ '^stashd_tx_test_';"

lerd php -r 'echo "php=" . PHP_VERSION . PHP_EOL . "tmp=" . sys_get_temp_dir() . PHP_EOL; $paths=glob(sys_get_temp_dir() . "/stashd-archives-*/projection-stashd_tx_test_*.json"); echo "residual_fixture_credential_descriptors=" . count($paths) . PHP_EOL; foreach ($paths as $path) { echo $path . PHP_EOL; }'
```

See [postgres-final.txt](postgres-final.txt), [runtime-credential-audit.txt](runtime-credential-audit.txt), and [cleanup-files.json](cleanup-files.json). Both temporary private admin files were unlinked; a scan of the PR and main checkout tmp directories found no admin JSON or generated projection credential descriptors. Temporary diagnostic PHP files, XML files, and disposable diagnostic Vaults were removed from the checkout after copying non-secret evidence here. The existing local PostgreSQL service credentials were neither changed nor removed. No new administrative role was created.

Final HEAD still equals 3534a4c, all tracked files match that commit, and the only untracked file in the PR worktree remains the pre-existing `.phpactor.json`. See [git-final.txt](git-final.txt). The PR remains open/draft. No cleanup was performed on its branch/worktree because the current instruction is verification only.

**Limits and unresolved risks**

No production/test mutation, independent machine/runner, power-cut/device-loss experiment, distributed fencing validation, cloud/backend durability test, complete OCFL standards validation, or fresh baseline static-analysis run was performed. The all-or-nothing projection rebuild limitation remains tracked in #60; a damaged unrelated Object can still block rebuilding healthy catalogue rows. Intra-Object ambiguous publication remains conservatively quarantined rather than automatically repaired. Thirty-three incomplete acceptance cases and the QA failures remain unresolved; they prevent an unqualified repository-wide green sign-off.

# PR 2 acceptance contracts: bounded directory runs

These contracts are implemented in the PR 2 draft; see [decisions and evidence](../filesystem-pr2-decisions.md).
PR 1 already handles one authentic source-independent file. PR 2 must preserve
**each** file in a directory through a distinct durable acquisition Job, without
rebuilding the established discovery, Catalogue, OCFL or Broadcast machinery.

The tests were demonstrated red against merged PR 1, then green through the real application. The tests-only #88 branch remains a contract, not a standalone implemented feature. Do not weaken the independent physical
checks or make the success receipts authoritative.

## Routine acceptance (small)

File: `tests/Acceptance/Integration/FilesystemDirectoryPr2Test.php`

- Three files, separate Items and per-file Jobs; actual OCFL v1 Item–Asset pins
  and hashes; unchanged second scan, stable Item identities, no additional
  versions; repeat publication into the same directory with identical inodes.
- Admit one per-file Demand, reject the next via a real PostgreSQL trigger, then
  remove the fault. Retrying the run must preserve all files; the cursor must
  never claim progress beyond independently committed durable per-file Demands.
- Hold the acquisition cursor write in PostgreSQL after admission, kill the real
  CLI/worker processes, release the barrier, restart, and independently prove the
  complete output set without missing Items or extra archival versions.
- Negative control: copy only **100 of 101** canonical reference paths into a
  disposable output. The Python verifier must reject the missing page, then
  accept the same output when the final file is restored. This deliberately
  exercises only the *external oracle*, never a simulated Stashd publication.

No giant corpus or invented performance threshold belongs in routine PR CI.

## Opt-in directory stress (100, 101 and 1,000)

File: `tests/Stress/Integration/FilesystemDirectoryPr2StressTest.php`.
This directory is **not** included in the normal `phpunit.xml` suites.
Run explicitly in the intended Lerd environment, with its disposable PostgreSQL
test-administrator fixture configured:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/Integration/FilesystemDirectoryPr2Test.php
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Stress/Integration/FilesystemDirectoryPr2StressTest.php
```

The opt-in test checks discovery and membership at each page boundary; verifies
a distinct Item and a *distinct durable per-source acquisition Job* for every
source; checks real OCFL inventories and byte hashes; rescans unchanged Input;
removes it; broadcasts exclusively from the Vault; checks every relative output
path against the external Microtext manifest; and requires repeated publication
to retain output inodes.

A PostgreSQL `AFTER INSERT` trigger on the real Operations Demand table
counts outstanding per-file Assignments and rejects admission above **100**.
It holds a test-only advisory lock while counting so concurrent admissions
cannot both pass by seeing the same old count. This verifies the bound at the
commit boundary, not just a potentially invented progress report.

When the implementation makes these tests green, add this opt-in suite to the
existing `.github/workflows/microtext-stress.yml` (post-merge into `dev`,
published release, manual dispatch). Do **not** add the 1,000-file workload to
normal pull-request CI. The existing pinned 10,000-file fixture stays reserved
for PR 5.

## Reviewable proposed public and persistence seams

PR 1's existing public commands remain in place, including `input:discover`,
`input:acquire`, `stash:items`, `stash:broadcast:select` and
`broadcast:build`. The PR 2 tests propose:

- **`input:admission <run-id>`**: read-only JSON snapshot with
  `run_id`, `total`, `admitted`, `settled`, `outstanding`, `cursor` and `status`.
  Status `complete` means every discovered file has settled; counts must be
  persisted observations, not guesses from a coordinator.
- **Per-file Job payloads** retain `runId` and `sourceId`. The independent
  PostgreSQL witnesses compare these with saved `discovery_items.source_id`
  records; a single run-wide Job cannot stand in for 1,000 individual files.
- **Last-admitted source cursor** is proposed as
  `discovery_runs.admission_cursor`. The cursor is inclusive and may
  advance only past durable per-file admission, not before it. The cursor
  checker compares actual persisted Operations Demands to all discoveries
  through that source ID.
- **Run-level `input:acquire`** remains the caller entry point, returns a
  per-file Assignment receipt from its bounded admission batch, and is safe to invoke repeatedly to resume an unfinished
  admission run. Per-file Jobs are admitted in bounded pages. Repeated active
  requests coalesce where appropriate; retries after a *terminal failure* may
  create another Assignment but must not duplicate canonical archival versions.

These command names, one internal cursor column and serialized input property
names are **proposed for discussion**, not immutable design decisions. If the
implementation finds a cleaner way to expose the same evidence, explicitly
review and update the test probe *before implementation*, rather than silently
making it pass through a mock, direct file copy or private SQL completion writes.

## What these tests do *not* claim

- They do not establish a high-throughput benchmark or prove the 10,000-file
  production stress round trip; that is PR 5.
- They do not infer renames, change source deletion policy or add watchers.
- The tests' SQL fault triggers operate only in the throwaway
  `configuration_proof` database created by the existing fixture and are
  removed afterward. No live database, external filesystem or private key is
  accessed.
- While authoring tests-only #88, the suite and full Lerd QA had not been run.
  The implementation now records actual passing results in its decisions note.

## Implementation review of the proposed seam

`input:acquire` is a bounded refill command, not an extra run-wide execution Job.
Its `assignment_id` refers to an actual per-file acquisition Assignment (the first
persisted one for the run), retaining PR 1's receipt and recovery semantics.
Run completion is observed through `input:admission`, not by treating that one
Assignment as the whole directory. Call `input:acquire` again after workers
settle to refill capacity. The harness already supports direct admission; its
receipt explanation is clarified accordingly. No behavioural assertion is removed.
The cursor crash proof additionally requires an independently committed per-file
Demand at the barrier, so a transaction that has not really admitted work cannot
pass as the requested after-admission interruption.

The 101-file stress case also starts two real refill CLI processes before draining
workers and requires exactly 100 outstanding Assignments. The existing SQL guard
checks admission throughout. An added empty-run case prevents a scan with no
saved observations yet from prematurely reporting acquisition completion.

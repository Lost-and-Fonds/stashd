# OPS-C02 negative controls

This is a focused Python 3 standard-library verifier for the eight delivery
controls in `docs/development/durable-delivery.md`. It adds no production API
and never edits the approved PR #77 specifications. Run it from an idle Core
checkout with no other tests, editors, formatters or agents changing that
checkout. Other worktrees may contain uncommitted work; they are read only.

## Prerequisites and invocation

Use Linux/POSIX, Python 3.9+ (`fcntl` is required), the locked Composer
installation, and the normal Lerd PHP/PostgreSQL environment. Configure the
private ignored `tmp/transaction-projection-admin.json` using the existing
local test administrator as documented in
`docs/preservation/recoverable-transactions-phase2.md`. Never use application
or production credentials. CI can use the disposable service and hostname
mapping in `.github/workflows/ci.yml`; the runner invokes `bin/test.sh`, so
its existing environment routing remains authoritative.

From any directory:

```sh
python3 -B /path/to/core/tools/verification/ops_c02_negative.py
```

From the checkout, run its small classifier/restoration regression checks:

```sh
python3 -B -m unittest discover -s tools/verification -p test_ops_c02_negative.py -v
python3 -B tools/verification/ops_c02_negative.py
```

The script resolves Core from its own file, requires a passing seven-contract
baseline, then temporarily points only the projection fixture's test-admin
lookup at a guaranteed absent file. That real setup failure must be classified
as **verification error**, not detected sabotage. The real credential file is
never renamed, read or copied by this verifier.

Each of the original eight mutations runs separately through the canonical
wrapper. PHPUnit JUnit reports establish the selected test, exception class
and exact first failure location. The native PHPUnit event stream separately
rejects setup/teardown hook errors, even when another assertion already failed. Captured console diffs supply the observed
Job/effect IDs and physical ordering that this installed JUnit reporter omits.
The lost-outbox control requires a separate persisted-state diagnostic: one
queued Job, zero native envelopes, zero effects after the settlement deadline.
The removed-barrier control requires a successful, un-killed child exit before
the barrier. Neither control accepts a generic timeout or arbitrary exception.

**detected** means that precise violation was observed. **not detected** means
the mutated contract passed. **verification error** means setup, cleanup,
infrastructure, source drift, unexpected output, or an unrelated failure
prevented a valid result. All controls must be detected; any unexpected
verification error fails the run. The intentionally broken fixture regression
is the sole expected verification error. A restored seven-contract run must
also pass before the overall result can pass.

## Isolation, interruption and restoration

The existing fixtures create private PostgreSQL databases, schemas and
unprivileged roles and bounded real runtime processes. Their teardown owns
resource cleanup. The verifier never restarts shared services or modifies
another worktree. Mutations require normal, unshared files: symlinks and
hardlinks are refused. A checkout-local lock prevents concurrent verifier runs.

Every mutation saves the original bytes privately, restores them in `finally`,
and verifies their hash. Existing uncommitted bytes are the restoration source;
`git checkout`, `reset`, `clean` and index writes are never used. All worktrees'
status, diffs and dirty/untracked file hashes must match their initial snapshots.
If an outside process edits a mutation target, its unexpected bytes are preserved
and the run fails rather than overwriting that edit; the original backup remains. Backup names are the SHA-256 of the relative target
path followed by `.original`; the five mutation targets are listed in the script.

SIGINT and SIGTERM defer stopping until the current bounded test has completed
its teardown and the source has been restored, then fail the overall run.
The wrapper has a 90-second deadline. Uncatchable SIGKILL, machine failure or
filesystem failure cannot be repaired by Python `finally`: private
`tmp/ops-c02-verification/*.original` backups remain for manual inspection and
recovery. A later run refuses leftover backups. Do not run this against an
installation or checkout used for live traffic.

## Attachable evidence

`tmp/ops-c02-verification/manifest.json` describes every mutation, expected
invariant, actual result, exact assertion location, observed IDs/counts/order,
source hashes and working-tree status hashes. It is an allowlisted JSON artifact:
no raw exception output, credentials, environment dumps, absolute paths, tokens
or private database passwords are copied into it. Raw console output is held
in an anonymous temporary file and discarded; temporary JUnit reports and hook-event logs are also removed. The existing driver's ignored native evidence stream remains local.

A sanitized successful run is retained at
`docs/development/verification/ops-c02/negative-controls.json`. After reviewing
a fresh manifest, copy it there or attach it to the implementation PR. Never
attach the entire `tmp/` directory or fixture credential files. Remove any
private administrator file created for verification after all QA is complete.

These checks cover the bounded local PostgreSQL/Doctrine crash windows only.
They do not establish OPS-C03 fencing/renewal, remote-broker behavior or
exactly-once arbitrary external effects.

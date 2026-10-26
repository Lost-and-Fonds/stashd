# Continuous integration

`.github/workflows/ci.yml` runs on pull requests to `dev`, pushes to `dev` and
`main`, and manual dispatch. One Ubuntu 24.04 job uses PHP 8.5, Composer 2.10.3,
and a disposable PostgreSQL 16.13 service. Actions are pinned to commit SHAs.
Only Composer download archives are cached, keyed by the committed lockfile;
`vendor/`, discovery caches, fixture credentials and databases are never cached.
Dependencies, including development tools, are installed from `composer.lock`.

Checks use the existing non-mutating `github:fmt`, `github:lint` and
`github:analyse` Composer scripts, then `./bin/test.sh --compact --colors=never`.
The wrapper's existing `CI` path calls Pest directly, without Lerd. One test
process runs the entire configured suite, including Filesystem, PostgreSQL,
OCFL, HTTP, SIGKILL, transaction recovery and historical-pin proofs. Intentional
incomplete tests remain reported as incomplete, not converted to passing tests.
The local `composer qa` command is not used in CI because it includes formatting
and lint fixes. A final tracked-file diff check detects accidental mutation.

## PostgreSQL isolation

No fixture safety rule or administrator DSN is relaxed. The runner maps the
existing, strictly validated `lerd-postgres:5432` endpoint to its own loopback
service in `/etc/hosts`. The fixed private test-admin JSON is created with mode
0600 just for the job and removed in an `always()` step. Its password is only for
the disposable service, not a repository secret or application credential. No
artifacts are uploaded. The service container and runner are destroyed after
success, failure, timeout or cancellation, including any fixtures whose process
was interrupted before its normal `finally` cleanup.

Fixtures still accept only generated `stashd_tx_test_` database/role/schema
identities and verify actual database ownership, lack of elevated privileges and
absence of role memberships before destructive work. Existing wrong-target tests
also reject direct loopback DSNs, changed ports and appended DSN fields. The
administrator controls only the CI service; do not use these settings with an
application database. Local Lerd's endpoint and private configuration remain
unchanged. This networking adaptation avoids adding a second configurable test
administrator or a CI-only fixture implementation.

## Local reproduction

From the Core checkout, install the lockfile and configure the existing private
Lerd test administrator as described in
`../preservation/recoverable-transactions-phase2.md`. Then run:

```sh
lerd composer install --no-interaction --prefer-dist
lerd composer github:fmt
lerd composer github:lint
lerd composer github:analyse
STASHD_TEST_PROCESSES=1 ./bin/test.sh --compact --colors=never
git diff --exit-code
```

On a disposable non-Lerd Linux host with PHP 8.5 and PostgreSQL 16, use the same
loopback hostname mapping and temporary configuration as the workflow, then run
`CI=true STASHD_TEST_PROCESSES=1 ./bin/test.sh --compact --colors=never`.
Never map that hostname to a persistent or remote database. Remove the private
configuration afterwards. The workflow has a 20-minute job limit and a 12-minute
test limit; superseded runs are cancelled. No branch protection, deployment,
release publishing or coverage service is configured here.

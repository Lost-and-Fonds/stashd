# Native Operations negative verification

Use the repository's canonical test wrapper, real PostgreSQL fixtures and an idle
evaluation checkout. No test, formatter or editor may change mutation targets while
these commands run. The scripts accept the existing uncommitted candidate as their
restoration source; they never reset Git or touch another worktree.

```sh
python3 -B -m unittest discover -s tools/verification -p 'test_*.py'
python3 -B tools/verification/ops_c02_negative.py
python3 -B tools/evaluations/installation-negative.py
```

The former relay verifier now checks native delivery. Its ten-case baseline must
pass, then nine mutations separately break atomic admission, retained intent,
completed-Job guards, independent effects, generation guards, busy recovery,
physical transaction sharing, native priority propagation and actual queue order.
A passing restored native suite is required. Original relay-only mutation targets
were removed together with their transport; their reliability correspondence is
in docs/development/durable-delivery.md.

The nesting verifier checks premature physical commit, a separate connection and
bypassed DBAL transaction ownership against the real application. Both tools share
the classifier, bounded process runner, mutation lock and exact-byte restoration
helper. Linked mutation targets are refused. Concurrent edits are preserved and
reported, not overwritten. SIGINT/SIGTERM defer interruption until the current
bounded test completes cleanup and source is restored. SIGKILL or machine failure
can leave a private original backup under tmp/ for manual inspection.

JUnit must report one selected expectation failure at the intended first frame.
The event log must contain no setup/teardown errors. Other exceptions, wrong
assertions, malformed reports, multiple failures, passing mutants and timeouts
are verification errors. Python regressions cover these counterexamples,
restoration of dirty source, interrupted runs and the POSIX test wrapper's routes.

The native test runner has a 120-second deadline and individual application
children have shorter deadlines. PostgreSQL and filesystem cleanup are independently
asserted by the fixtures. Private JUnit/events/output stay under ignored
`tmp/ecotone-native-negative/` or `tmp/ecotone-installation-negative/`; credentials
and raw error output must not be attached to a PR. Checked-in allowlisted results
are in docs/evaluations/ecotone-native-negative.json and
ecotone-installation-negative.json.

These controls establish bounded local PostgreSQL behaviour, not remote broker
recovery, arbitrary exactly-once effects, OPS-C03 fencing or lease renewal.

# Final gates before Messenger removal

These contracts add to OPS-C01, OPS-C02 and OPS-C02-E without changing them.

## Clean installation

Start with a newly allocated restricted PostgreSQL database containing no domain,
delivery or migration tables. Boot the real application, run all migrations and
explicit queue provisioning, then admit through Jobs::request and consume in a
fresh native process. Observe one completed Job, one completed Attempt, a separate
non-deduplicated effect and an empty queue. Admit a second Work identity, record
its actual native invitation, provision again, and require identical retained
intent before consuming it. Every fresh lifecycle process rejects Messenger class autoloading, so a hidden
transport/service dependency fails the run even while the package is installed
in the candidate. The installation must never create messenger_messages.
The assertion remains failing while the legacy migration creates it; do not skip,
rewrite migration history or turn this into a green expectation of legacy schema.

## Nested transactions

Use normal application resolution and a fresh observer connection for each case:
outer commit; outer rollback; real queue-write failure inside admission. An outer
Tempest transaction mutates ordinary state and calls Jobs::request. Mutation, Job
and native invitation must all be invisible independently until the outer commit.
A Job insertion trigger and native enqueue insertion trigger record backend PID and
transaction ID for comparison with the Tempest mutation; matching DSNs is not proof.
For the inner failure, a real PostgreSQL constraint rejects enqueue, both inner
Job and invitation roll back to the savepoint, the outer transaction remains usable,
and unrelated Tempest work still commits. Record observed outcomes even if a bad
adapter prematurely commits, so its visible partial writes fail a specific assertion.

Negative controls must prematurely commit the physical inner connection and supply
a different physical Job connection. Only named visibility/identity assertion
violations count; setup errors, timeouts and unrelated exceptions do not.

## Official integration profile

Install the pinned official ecotone/tempest package in the isolated Composer fixture
under tools/evaluations/official-tempest, not in Core's runtime installation.
Use supported Tempest discovery/configuration and TempestConnectionReference.
The official profile excludes only the candidate's connection/runtime initializers
and provisioning binding; it keeps the actual application source and migrations.
No private state is patched to make the official bridge pass. If transaction
ownership or nesting fails, retain the working candidate and its failing official
contract as evidence. The reflection-based official bridge is an explicit choice
for evaluation, not justification for a Stashd workaround.

## Injected official connection with Tempest PR 2326

InjectedIntegrationTest adds the same nesting assertions for the proposed
DbalBackedConnection API supplied by the isolated ecotone-tempest-injection.patch.
The fixture now installs the exact Tempest fork commit in its separate lockfile.
The unmodified default official adapter still runs through OfficialIntegrationTest
and retains its three failures; no original assertion changes.

Two lifecycle cases resolve Database first or messaging first, reconnect to a
non-default private schema using Tempest configuration, reject active transaction
replacement, and consume committed native intent in a fresh official process.
The injected path must use the same DBAL owner, PostgreSQL PID and transaction ID.
It must not invoke Stashd's custom shared-connection classes or reflection.

Run installation-negative.py --injected to sabotage premature physical commit,
physical connection sharing and DBAL nesting ownership. Each control must fail at
its named behavioral assertion, with a passing baseline and restored test. Setup
errors and timeouts are verification errors. See docs/evaluations/ecotone.md for
the explicit patch/install commands and the remaining migration obligations.

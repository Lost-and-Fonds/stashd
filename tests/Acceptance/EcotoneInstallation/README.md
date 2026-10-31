# Real application installation and transaction contracts

All cases now use the real application, the official ecotone/tempest integration
and its pinned local development patches. There is no alternate Composer profile,
manually constructed Tempest container or candidate connection bridge.

The fresh-install test starts with a generated database containing no application
schema tables. Normal application migrations and explicit native provisioning must
allow Jobs::request and a fresh consumer to produce a completed Job, completed
Attempt, independent effect and empty queue. Reprovisioning must retain a second
pending invitation's exact ID/body. No messenger_messages relation may exist.

Nested cases preserve independent invisibility before outer commit, atomic commit,
outer rollback and actual inner enqueue rejection/savepoint recovery. PostgreSQL
insertion triggers compare backend PID and transaction ID with the ordinary
Tempest mutation; matching DSNs is insufficient. The outer transaction must remain
usable and commit unrelated work after the inner failure. The former official
adapter's three red cases now select the real binding and messaging-first startup;
the database-first cases retain their original assertions.

The lifecycle cases resolve persistence or messaging first, use nonpersistent
connections and a non-default schema, require a changed physical backend on
reconnect, reject active transaction replacement, and execute real Work through a
fresh native consumer. The old standalone diagnostic command is replaced by the
real Work/Job/Attempt/effect path. Historical file labels identify the acceptance
provenance; they do not select separate runtime architectures.

Native CLI provisioning/consumption is tested independently. Production-cache
cases reuse compiled definitions across two generated installations and verify
that their actual Jobs, Attempts, effects and queues remain separate.

The nesting negative verifier must detect premature commit, an accidentally
separate owner and bypassed DBAL nesting at their intended assertions. Baseline
and restored tests must pass; setup errors and timeouts are verification errors.
See docs/evaluations/ecotone.md and docs/development/durable-delivery.md for commands,
exact dependencies, the old-to-new correspondence and proof limits.

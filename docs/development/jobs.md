# Operations startup and recovery

Configure STASHD_OPERATIONS_DSN, STASHD_OPERATIONS_USERNAME,
STASHD_OPERATIONS_PASSWORD and STASHD_OPERATIONS_SCHEMA. PostgreSQL must exist and
the application role must be allowed to create its private schema. Stashd supplies
search_path as a PostgreSQL startup option on every connection; an independent
DSN `options=` field is rejected instead of silently overriding it.

For a clean installation:

```sh
composer install
php tempest integration:init
php tempest ecotone:migration:database:setup --initialize
php tempest ecotone:run jobs --memoryLimit=256 --executionTimeLimit=300000
```

Composer installs the pinned Tempest development fork and applies the three
checksum-locked Ecotone patches. Git is required in the dependency build environment.
The application uses the official Tempest integration and normal dependency
injection. See docs/evaluations/ecotone.md for exact versions and patch provenance.

The first command after dependency installation creates ordinary application tables
using Tempest migrations. Ecotone's setup command idempotently creates only its used
native queue/dead-letter infrastructure, preserving pending invitations. Run setup
once before starting consumers; it also warms the application messaging definitions.
There is no legacy database upgrade, message conversion, outbox relay, job:work
wrapper or intermediate queue. New databases never create messenger_messages.

Run one or more supervised `ecotone:run jobs` processes. The shown limits exit a
worker after its memory/time bound; the supervisor must restart it. Do not enable
`--stopOnFailure` for ordinary workers. For a bounded smoke test add
`--finishWhenNoMessages`. Tempest retains scheduling; the unused command:monitor
activation was removed from .lerd.yaml. No running local worker or service is
started or changed by this implementation.

Work enters through Jobs::request. The retained keyed Job example is available as
`php tempest job:admit example-key hello`; its key stays reserved after completion,
and a different payload for that key is rejected. It uses the same native channel,
transaction owner and Attempt history as Work. Real Work requires a departmental
WorkExecutor; an unregistered executor produces failed Attempts, not success.

Native visibility and Job leases are both 60 seconds. A valid running claim
receiving another invitation retains delayed native recovery intent. Busy handling
uses no domain failure budget. Expired claims can recover, but live stale workers
are not externally fenced and leases are not renewed. Work must obey this bounded
execution policy until OPS-C03 is separately implemented.

Infrastructure errors have an initial delivery plus three delayed native retries,
then a retained row in ecotone_error_messages. Inspect jobs/job_attempts separately:
a transport dead letter is not a domain Job failure. After diagnosing the failure,
use the native commands:

```sh
php tempest ecotone:deadletter:list
php tempest ecotone:deadletter:show MESSAGE_ID
php tempest ecotone:deadletter:replay MESSAGE_ID
```

Replay retains the Job identity. If enqueue fails, the dead letter remains; the
acceptance suite proves this through the real CLI. Replaying a completed or obsolete
invitation cannot start another Attempt. Domain handler failures have their own
three-failure budget and truthful failed Job/Attempt history.

Queue deserialization and dead-letter access require trusted database roles.
Keep code, locked dependencies and payload contracts consistent across producers
and consumers. For deployment, stop consumers, install dependencies/patches, run
migrations and explicit native setup, clear/warm framework caches as needed, then
restart workers. `ecotone:cache:clear` clears only the current application root's
native definitions; the cache path is namespaced by that root. Do not clear caches
under live workers. Production-cache tests reuse definitions across independent
installations without sharing connections or effects.

Monitor queued/running Job age, unfinished Attempts, dead-letter count, worker
health and database availability. A queued Job stranded in dead letters is pending
operator recovery, not completed. Canonical Work, priorities and reconciliation
obligations are described in work-admission.md. Fault proofs and their limits are
in durable-delivery.md. No arbitrary exactly-once effect or remote-broker guarantee
is implied by completion or replay.

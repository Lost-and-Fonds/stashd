# Example background jobs

Configure `STASHD_OPERATIONS_DSN`, `STASHD_OPERATIONS_USERNAME`, `STASHD_OPERATIONS_PASSWORD` and optionally `STASHD_OPERATIONS_SCHEMA` as for the existing integration store. The PostgreSQL database and schema owner must already exist. Run the following in the application environment:

```sh
php tempest integration:init
php tempest job:work outbox
php tempest job:work jobs
```

Run the last two commands in separate supervised processes. Admit an example job in another process:

```sh
php tempest job:admit example-key hello
```

The command prints its Job ID. Reusing the key and payload returns the same Job; using that key with a different payload fails. The key remains reserved after completion. Payload `fail` exercises the three-attempt failure path. Inspect `jobs` in the configured operations schema for status, attempts and error. `--once` stops a worker when its queue is empty and is useful for tests, not long-running supervision.

Job insertion and outbox insertion share one DBAL transaction. The separate Tempest connection cannot join that transaction; do not claim atomicity with other Tempest writes. The existing Tempest migration machinery creates both tables. Messenger's Doctrine transport relays its native outbox to a second PostgreSQL queue; both queues require running workers. Delivery is at least once and relay failure is retried. Job claims expire after 60 seconds, and an unacknowledged transport message is redelivered after 60 seconds. Completion is fenced by the claim token and expiry. Retryable handler failure advances the delivery generation and commits a replacement outbox message with its state change. Older duplicate deliveries cannot claim the new generation or bypass its delay.

This is a deliberately short example, not an execution framework. It has no claim renewal, cancellation, priority promotion, manual retry, or safe mechanism for long-running work. An expired worker can still perform external side effects, so future handlers must make effects idempotent or independently fenced. The example has no external side effects. Transport failures are retried ten times, then retained in the `failed` queue for operator investigation. A Job may remain queued or running if its delivery reaches that queue; monitor it and do not treat queue exhaustion as Job failure. After fixing the underlying problem, obtain the message ID from `messenger_messages` where `queue_name = 'failed'` and run `php tempest job:retry-message <message-id>`. Redispatch and removal from the failed queue commit together; a failed redispatch keeps the original. A busy claim is retried by Messenger as a recoverable error and can continue retrying until the claim expires. PHP-serialized messages require trusted database access and compatible deployments. Messenger 8.2 is a development dependency and its API may change.

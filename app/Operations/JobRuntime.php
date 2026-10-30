<?php

namespace App\Operations;

use App\Integration\OperationsConfig;
use App\Operations\DTO\ExecutionContext;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\DbalConnection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Evaluate durable Jobs on a native Ecotone PostgreSQL channel.
 *
 * Job and channel writes share this DBAL connection, not the separate Tempest
 * connection. Claims fence state changes but cannot fence external effects.
 */
final class JobRuntime
{
    private Connection $database;
    private ConfiguredMessagingSystem $messaging;

    /**
     * Build real transports on one connection; domain Work requires an executor.
     */
    public function __construct(
        OperationsConfig $config,
        private ?WorkExecutor $executor = null,
        ?Connection $connection = null,
    ) {
        if (! str_starts_with($config->dsn, 'pgsql:') || $config->username === '') {
            throw new InvalidArgumentException('Explicit PostgreSQL operations credentials are required.');
        }

        $parts = [];
        foreach (explode(';', substr($config->dsn, 6)) as $part) {
            if ($part === '') {
                continue;
            }

            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || ! in_array($pair[0], ['host', 'port', 'dbname', 'sslmode', 'sslrootcert', 'sslcert', 'sslkey'], true)) {
                throw new InvalidArgumentException('Unsupported PostgreSQL operations DSN.');
            }

            $parts[$pair[0]] = $pair[1];
        }

        $this->database = $connection ?? DriverManager::getConnection([...$parts, 'driver' => 'pdo_pgsql', 'user' => $config->username, 'password' => $config->password]);
        $this->database->executeStatement('SET search_path TO "' . $config->schema . '"');
        // Spike only: production must provision Ecotone tables before starting consumers.
        new EnqueueTableManager('enqueue', true)->createTable($this->database);
        $this->messaging = EcotoneLite::bootstrap(
            classesToResolve: [self::class],
            containerOrAvailableServices: [self::class => $this, DbalConnectionReference::DEFAULT => DbalConnection::create($this->database)],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create('jobs')->withReceiveTimeout(1),
                DbalConfiguration::createWithDefaults()
                    ->withTransactionOnAsynchronousEndpoints(false)
                    ->withTransactionOnCommandBus(false)
                    ->withDeduplication(false)
                    ->withDeadLetter(false),
            ],
        );
    }

    /**
     * Retain the example API's permanent key and payload compatibility contract.
     */
    public function admit(string $key, string $payload): string
    {
        if ($key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('An idempotency key of at most 255 bytes is required.');
        }

        return $this->database->transactional(function (Connection $database) use ($key, $payload): string {
            $id = Uuid::v7()->toString();
            $inserted = $database->fetchOne("INSERT INTO jobs (id, idempotency_key, payload, status) VALUES (?, ?, ?, 'queued') ON CONFLICT (idempotency_key) DO NOTHING RETURNING id", [
                $id,
                $key,
                $payload,
            ]);
            if ($inserted === false) {
                $existing = $database->fetchAssociative('SELECT id, payload FROM jobs WHERE idempotency_key = ?', [$key]);
                if ($existing === false || $existing['payload'] !== $payload) {
                    throw new InvalidArgumentException('The idempotency key already belongs to different work.');
                }

                return (string) $existing['id'];
            }

            $this->dispatchWork($id, 0, 'routine');

            return $id;
        });
    }

    /**
     * Join equivalent active Work or save a fresh Job and delivery together.
     *
     * The PostgreSQL transaction lock serializes only this canonical identity.
     * Promotion advances queued delivery generation; running work is not restarted.
     * Later observations coalesce without replacing the admitted input snapshot.
     */
    public function admitWork(Work $work, string $priority = 'routine'): string
    {
        if (! in_array($priority, ['routine', 'interactive'], true)) {
            throw new InvalidArgumentException('Use routine or interactive priority.');
        }

        return $this->database->transactional(function (Connection $database) use ($work, $priority): string {
            $identity = [$work->type, $work->scope, $work->mode];
            $database->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode($identity, JSON_THROW_ON_ERROR)]);
            $job = $database->fetchAssociative(
                "SELECT id, status, generation, desired_priority FROM jobs WHERE work_type = ? AND work_scope = ? AND work_mode = ? AND status IN ('queued', 'running') FOR UPDATE",
                $identity,
            );
            if ($job !== false) {
                if ($priority === 'interactive' && $job['desired_priority'] === 'routine') {
                    $queued = $job['status'] === 'queued';
                    $generation = (int) $job['generation'] + (int) $queued;
                    $database->executeStatement('UPDATE jobs SET desired_priority = ?, generation = ? WHERE id = ?', [$priority, $generation, $job['id']]);
                    if ($queued) {
                        $this->dispatchWork((string) $job['id'], $generation, $priority);
                    }
                }

                return (string) $job['id'];
            }

            $id = Uuid::v7()->toString();
            $database->executeStatement("INSERT INTO jobs (id, idempotency_key, payload, status, work_type, work_scope, work_mode, desired_priority) VALUES (?, ?, ?, 'queued', ?, ?, ?, ?)", [
                $id,
                $id,
                $work->observation,
                ...$identity,
                $priority,
            ]);
            $this->dispatchWork($id, 0, $priority);

            return $id;
        });
    }

    /**
     * Save priority routing in the same outbox transaction as its Job revision.
     */
    private function dispatchWork(string $id, int $generation, string $priority, int $delay = 0): void
    {
        $this->messaging->getCommandBus()->send(new ExecuteJob($id, $generation), metadata: [
            MessageHeaders::PRIORITY => $priority === 'interactive' ? 10 : 0,
            MessageHeaders::DELIVERY_DELAY => $delay,
        ]);
    }

    /**
     * Claim one current delivery and record an actual Attempt before executing.
     *
     * Old generations and completed Jobs cannot start another Attempt. Expired
     * claims remain recoverable; an expired process may still affect remote systems.
     */
    #[Asynchronous('jobs')]
    #[CommandHandler(endpointId: 'operations.execute')]
    public function handle(ExecuteJob $message): void
    {
        $token = Uuid::v7()->toString();
        $job = $this->database->transactional(function (Connection $database) use ($token, $message): array|false {
            $job = $database->fetchAssociative(
                "UPDATE jobs SET status = 'running', claim_token = ?, lease_until = clock_timestamp() + interval '60 seconds', attempts = attempts + 1 WHERE id = ? AND generation = ? AND (status = 'queued' OR (status = 'running' AND lease_until <= clock_timestamp())) RETURNING payload, failures, work_type, work_scope, work_mode, desired_priority, lease_until",
                [$token, $message->id, $message->generation],
            );
            if ($job !== false) {
                $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'interrupted' WHERE job_id = ? AND ended_at IS NULL", [$message->id]);
                $database->executeStatement('INSERT INTO job_attempts (id, job_id) VALUES (?, ?)', [$token, $message->id]);
            }

            return $job;
        });
        if ($job === false) {
            $status = $this->database->fetchOne('SELECT status FROM jobs WHERE id = ? AND generation = ?', [$message->id, $message->generation]);
            if ($status === 'running') {
                throw new RuntimeException('Job is already claimed.');
            }

            if ($status === false && $this->database->fetchOne('SELECT id FROM jobs WHERE id = ?', [$message->id]) === false) {
                throw new RuntimeException('Job does not exist.');
            }

            return;
        }

        try {
            if ($job['work_type'] !== null) {
                if ($this->executor === null) {
                    throw new RuntimeException('No domain Work executor is registered.');
                }

                $this->executor->execute(
                    new Work($job['work_type'], $job['work_scope'], $job['work_mode'], $job['payload']),
                    new ExecutionContext($message->id, $token, $message->generation, new DateTimeImmutable($job['lease_until'])),
                );
            }

            if ($job['work_type'] === null && $job['payload'] === 'fail') {
                throw new RuntimeException('Example handler failed.');
            }
        } catch (Throwable $error) {
            $failed = ((int) $job['failures'] + 1) >= 3;
            $this->database->transactional(function (Connection $database) use ($failed, $error, $message, $token, $job): void {
                $updated = $database->executeStatement(
                    'UPDATE jobs SET status = ?, failures = failures + 1, generation = generation + 1, claim_token = NULL, lease_until = NULL, error = ? WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()',
                    [
                        $failed ? 'failed' : 'queued',
                        $error->getMessage(),
                        $message->id,
                        $token,
                    ],
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Job claim expired before recording failure.');
                }

                $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'failed' WHERE id = ?", [$token]);
                if (! $failed && $job['work_type'] !== null) {
                    $priority = (string) $database->fetchOne('SELECT desired_priority FROM jobs WHERE id = ?', [$message->id]);
                    $this->dispatchWork($message->id, $message->generation + 1, $priority, 1000);
                } elseif (! $failed) {
                    $this->dispatchWork($message->id, $message->generation + 1, 'routine', 1000);
                }
            });

            return;
        }

        $this->database->transactional(function (Connection $database) use ($message, $token): void {
            if (
                $database->executeStatement("UPDATE jobs SET status = 'completed', claim_token = NULL, lease_until = NULL, error = NULL WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()", [
                    $message->id,
                    $token,
                ]) !== 1
            ) {
                throw new RuntimeException('Job claim expired before completion.');
            }

            $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'completed' WHERE id = ?", [$token]);
        });
    }

    /**
     * Run the native database consumer without a relay process.
     *
     * This spike leaves transport failure recovery unconfigured; do not deploy it.
     */
    public function work(string $receiver, bool $once = false, ?int $limit = null): void
    {
        if ($receiver !== 'jobs') {
            throw new InvalidArgumentException('Ecotone uses jobs directly; there is no relay.');
        }

        $metadata = ExecutionPollingMetadata::createWithDefaults()
            ->withStopOnError(true);
        if ($once && $limit === null) {
            $metadata = $metadata->withFinishWhenNoMessages(true);
        }

        if ($limit !== null) {
            $metadata = $metadata->withHandledMessageLimit($limit)->withExecutionTimeLimitInMilliseconds(2000);
        }

        $this->messaging->run('jobs', $metadata);
    }
}

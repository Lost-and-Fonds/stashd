<?php

namespace App\Operations;

use App\Operations\DTO\ExecutionContext;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tempest\DbalBackedConnection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\Connection\Connection as TempestConnection;
use Throwable;

/**
 * Admit, claim and complete durable domain Jobs on the native PostgreSQL channel.
 *
 * Normal Tempest bootstrap supplies the shared application connection. Claims fence state changes but cannot fence external effects.
 */
#[\Tempest\Container\Singleton]
final class JobRuntime
{
    /**
     * Observe the last claim decision's database time without granting authority.
     */
    public private(set) ?DateTimeImmutable $claimDecidedAt = null;

    private Connection $database;
    private AttemptAuthority $authority;

    /**
     * Use the application-owned transaction boundary and the framework command bus.
     * Claims are short transactions; domain execution and external effects run outside them.
     */
    public function __construct(
        TempestConnection $connection,
        private CommandBus $commands,
        private ?WorkExecutor $executor = null,
        private LeaseConfig $lease = new LeaseConfig(),
    ) {
        if (! $connection instanceof DbalBackedConnection) {
            throw new \LogicException('Operations requires the shared Ecotone/Tempest connection.');
        }

        $this->database = $connection->database();
        $this->authority = new AttemptAuthority($connection, $this->lease);
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
     * Save the native invitation in the same transaction as its Job revision.
     */
    private function dispatchWork(string $id, int $generation, string $priority, int $delay = 0): void
    {
        $this->commands->send(new ExecuteJob($id, $generation), metadata: [
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
            $database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$message->id]);
            $job = $database->fetchAssociative(
                "WITH decision AS MATERIALIZED (SELECT clock_timestamp() AS at), claimed AS (UPDATE jobs SET status = 'running', claim_token = ?, lease_until = decision.at + (? * interval '1 millisecond'), attempts = attempts + 1 FROM decision WHERE id = ? AND generation = ? AND (status = 'queued' OR (status = 'running' AND lease_until <= decision.at)) RETURNING payload, failures, work_type, work_scope, work_mode, desired_priority, lease_until) SELECT decision.at AS decision_at, claimed.* FROM decision LEFT JOIN claimed ON true",
                [$token, $this->lease->milliseconds, $message->id, $message->generation],
            );
            if ($job === false) {
                throw new \LogicException('The database did not return a claim decision.');
            }

            $this->claimDecidedAt = new DateTimeImmutable($job['decision_at']);
            if ($job['lease_until'] === null) {
                return false;
            }

            $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'interrupted' WHERE job_id = ? AND ended_at IS NULL", [$message->id]);
            $database->executeStatement('INSERT INTO job_attempts (id, job_id) VALUES (?, ?)', [$token, $message->id]);

            return $job;
        });
        if ($job === false) {
            $status = $this->database->fetchOne('SELECT status FROM jobs WHERE id = ? AND generation = ?', [$message->id, $message->generation]);
            if ($status === 'running') {
                // A valid claim is not a handler failure; retain a delayed native invitation.
                $priority = (string) $this->database->fetchOne('SELECT desired_priority FROM jobs WHERE id = ?', [$message->id]);
                $this->dispatchWork($message->id, $message->generation, $priority, 60_000);

                return;
            }

            if ($status === false && $this->database->fetchOne('SELECT id FROM jobs WHERE id = ?', [$message->id]) === false) {
                throw new RuntimeException('Job does not exist.');
            }

            return;
        }

        $context = new ExecutionContext($message->id, $token, $message->generation, new DateTimeImmutable($job['lease_until']));
        try {
            if ($job['work_type'] !== null) {
                if ($this->executor === null) {
                    throw new RuntimeException('No domain Work executor is registered.');
                }

                $this->executor->execute(
                    new Work($job['work_type'], $job['work_scope'], $job['work_mode'], $job['payload']),
                    $context,
                );
            }

            if ($job['work_type'] === null && $job['payload'] === 'fail') {
                throw new RuntimeException('Example handler failed.');
            }
        } catch (Throwable $error) {
            $failed = ((int) $job['failures'] + 1) >= 3;
            $accepted = $this->authority->finish($context, function (Connection $database) use ($failed, $error, $message, $token, $job): void {
                $database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$message->id]);
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
                    throw new AuthorityLost('Failure transition lost its claim.');
                }

                $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'failed' WHERE id = ?", [$token]);
                if (! $failed && $job['work_type'] !== null) {
                    $priority = (string) $database->fetchOne('SELECT desired_priority FROM jobs WHERE id = ?', [$message->id]);
                    $this->dispatchWork($message->id, $message->generation + 1, $priority, 1000);
                } elseif (! $failed) {
                    $this->dispatchWork($message->id, $message->generation + 1, 'routine', 1000);
                }
            });
            if (! $accepted) {
                $this->retainRecovery($message, $token);
            }

            return;
        }

        $accepted = $this->authority->finish($context, function (Connection $database) use ($message, $token): void {
            $database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$message->id]);
            if (
                $database->executeStatement("UPDATE jobs SET status = 'completed', claim_token = NULL, lease_until = NULL, error = NULL WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()", [
                    $message->id,
                    $token,
                ]) !== 1
            ) {
                throw new AuthorityLost('Completion transition lost its claim.');
            }

            $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'completed' WHERE id = ?", [$token]);
        });
        if (! $accepted) {
            $this->retainRecovery($message, $token);
        }
    }

    /**
     * Retain native recovery intent only if an expired claimant still owns the row.
     *
     * A superseded worker must never publish retries on behalf of its successor.
     */
    private function retainRecovery(ExecuteJob $message, string $token): void
    {
        $this->database->transactional(function (Connection $database) use ($message, $token): void {
            $job = $database->fetchAssociative('SELECT claim_token, desired_priority FROM jobs WHERE id = ? FOR UPDATE', [$message->id]);
            if ($job !== false && hash_equals($token, (string) $job['claim_token'])) {
                $this->dispatchWork($message->id, $message->generation, (string) $job['desired_priority']);
            }
        });
    }
}

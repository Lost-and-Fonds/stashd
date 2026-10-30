<?php

namespace App\Operations;

use App\Integration\OperationsConfig;
use App\Operations\DTO\ExecutionContext;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Failure\FailedMessageRepository;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\OutboxStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\OutboxSender;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Admit durable Jobs and run them through the transactional Messenger outbox.
 *
 * Job and outbox writes share this DBAL connection, not the separate Tempest
 * connection. Claims fence state changes but cannot fence external effects.
 */
final class JobRuntime
{
    private Connection $database;
    private DoctrineTransport $outbox;
    private DoctrineTransport $queue;
    private DoctrineTransport $failed;
    private DoctrineTransport $interactive;
    private MessageBus $bus;

    /**
     * Build real transports on one connection; domain Work requires an executor.
     */
    public function __construct(
        OperationsConfig $config,
        private ?WorkExecutor $executor = null,
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

        $this->database = DriverManager::getConnection([...$parts, 'driver' => 'pdo_pgsql', 'user' => $config->username, 'password' => $config->password]);
        $this->database->executeStatement('SET search_path TO "' . $config->schema . '"');
        $serializer = new PhpSerializer();
        $this->outbox = new DoctrineTransport(new TransportConnection(['queue_name' => 'outbox', 'auto_setup' => false, 'redeliver_timeout' => 60], $this->database), $serializer);
        $this->queue = new DoctrineTransport(new TransportConnection(['queue_name' => 'jobs', 'auto_setup' => false, 'redeliver_timeout' => 60], $this->database), $serializer);
        $this->failed = new DoctrineTransport(new TransportConnection(['queue_name' => 'failed', 'auto_setup' => false], $this->database), $serializer);
        $this->interactive = new DoctrineTransport(new TransportConnection([
            'queue_name' => 'jobs_interactive',
            'auto_setup' => false,
            'redeliver_timeout' => 60,
        ], $this->database), $serializer);
        $sender = new OutboxSender($this->queue, $this->outbox, 'jobs', 'outbox', 'failed');
        $senders = new JobServices(['jobs' => $sender, 'jobs_interactive' => new OutboxSender($this->interactive, $this->outbox, 'jobs_interactive', 'outbox', 'failed')]);
        $this->bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExecuteJob::class => ['jobs']], $senders)),
            new HandleMessageMiddleware(new HandlersLocator([ExecuteJob::class => [$this->handle(...)]])),
        ]);
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

            $this->bus->dispatch(new ExecuteJob($id));

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
        $this->bus->dispatch(new Envelope(new ExecuteJob($id, $generation), [
            new TransportNamesStamp([$priority === 'interactive' ? 'jobs_interactive' : 'jobs']),
            new DelayStamp($delay),
        ]));
    }

    /**
     * Claim one current delivery and record an actual Attempt before executing.
     *
     * Old generations and completed Jobs cannot start another Attempt. Expired
     * claims remain recoverable; an expired process may still affect remote systems.
     */
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
                throw new RecoverableMessageHandlingException('Job is already claimed.', retryDelay: 60_000);
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
                    throw new RecoverableMessageHandlingException('Job claim expired before recording failure.', retryDelay: 60_000);
                }

                $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'failed' WHERE id = ?", [$token]);
                if (! $failed && $job['work_type'] !== null) {
                    $priority = (string) $database->fetchOne('SELECT desired_priority FROM jobs WHERE id = ?', [$message->id]);
                    $this->dispatchWork($message->id, $message->generation + 1, $priority, 1000);
                } elseif (! $failed) {
                    $this->bus->dispatch(new Envelope(new ExecuteJob($message->id, $message->generation + 1), [new DelayStamp(1000)]));
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
                throw new RecoverableMessageHandlingException('Job claim expired before completion.', retryDelay: 60_000);
            }

            $database->executeStatement("UPDATE job_attempts SET ended_at = clock_timestamp(), outcome = 'completed' WHERE id = ?", [$token]);
        });
    }

    /**
     * Redispatch a retained transport failure atomically with its removal.
     */

    public function retryFailed(string $id): void
    {
        $this->database->transactional(function (Connection $database) use ($id): void {
            $row = $database->fetchOne("SELECT id FROM messenger_messages WHERE id = ? AND queue_name = 'failed' FOR UPDATE", [$id]);
            if ($row === false) {
                throw new InvalidArgumentException('Failed message not found.');
            }

            $envelope = $this->failed->find($id);
            if ($envelope === null) {
                throw new RuntimeException('Failed message could not be decoded.');
            }

            $this->bus->dispatch(
                FailedMessageRepository::prepareForRedispatch($envelope)->withoutAll(OutboxStamp::class)->withoutAll(RedeliveryStamp::class)->withoutAll(DelayStamp::class),
            );
            $this->failed->ack($envelope);
        });
    }

    /**
     * Drain real transports, preferring interactive deliveries at each dequeue.
     *
     * A limit counts messages, including obsolete deliveries, rather than effects.
     */
    public function work(string $receiver, bool $once = false, ?int $limit = null): void
    {
        $transports = match ($receiver) {
            'outbox' => ['outbox' => $this->outbox],
            'jobs' => ['jobs_interactive' => $this->interactive, 'jobs' => $this->queue],
            default => throw new InvalidArgumentException('Use outbox or jobs.'),
        };
        $events = new EventDispatcher();
        $strategies = [];
        $failures = [];
        foreach ($transports as $name => $transport) {
            $strategies[$name] = new MultiplierRetryStrategy(10, 1000, 2, 60_000, 0);
            $failures[$name] = $this->failed;
        }

        $events->addSubscriber(new SendFailedMessageForRetryListener(new JobServices($transports), new JobServices($strategies)));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new JobServices($failures)));
        if ($limit !== null) {
            $events->addSubscriber(new StopWorkerOnMessageLimitListener($limit));
        }

        new Worker($transports, $this->bus, $events)->run(['stop_when_empty' => $once]);
    }
}

/**
 * Supply Messenger's transport services without a second application container.
 */
final readonly class JobServices implements ContainerInterface
{
    /**
     * Retain the finite transport and retry services for this runtime.
     */
    public function __construct(
        private array $services,
    ) {}

    /**
     * Resolve the service requested by Messenger's configured routing.
     */
    public function get(string $id): mixed
    {
        return $this->services[$id];
    }

    /**
     * Report whether the runtime has registered this transport name.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

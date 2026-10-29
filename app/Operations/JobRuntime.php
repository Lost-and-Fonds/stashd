<?php

namespace App\Operations;

use App\Integration\OperationsConfig;
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
use Symfony\Component\Messenger\Transport\Sender\OutboxSender;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class JobRuntime
{
    private Connection $database;
    private DoctrineTransport $outbox;
    private DoctrineTransport $queue;
    private DoctrineTransport $failed;
    private MessageBus $bus;

    public function __construct(OperationsConfig $config)
    {
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
        $sender = new OutboxSender($this->queue, $this->outbox, 'jobs', 'outbox', 'failed');
        $senders = new JobServices(['jobs' => $sender]);
        $this->bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExecuteJob::class => ['jobs']], $senders)),
            new HandleMessageMiddleware(new HandlersLocator([ExecuteJob::class => [$this->handle(...)]])),
        ]);
    }

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

    public function handle(ExecuteJob $message): void
    {
        $token = Uuid::v7()->toString();
        $job = $this->database->fetchAssociative(
            "UPDATE jobs SET status = 'running', claim_token = ?, lease_until = clock_timestamp() + interval '60 seconds', attempts = attempts + 1 WHERE id = ? AND generation = ? AND (status = 'queued' OR (status = 'running' AND lease_until <= clock_timestamp())) RETURNING payload, failures",
            [$token, $message->id, $message->generation],
        );
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
            if ($job['payload'] === 'fail') {
                throw new RuntimeException('Example handler failed.');
            }
        } catch (Throwable $error) {
            $failed = ((int) $job['failures'] + 1) >= 3;
            $this->database->transactional(function (Connection $database) use ($failed, $error, $message, $token): void {
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

                if (! $failed) {
                    $this->bus->dispatch(new Envelope(new ExecuteJob($message->id, $message->generation + 1), [new DelayStamp(1000)]));
                }
            });

            return;
        }

        if (
            $this->database->executeStatement("UPDATE jobs SET status = 'completed', claim_token = NULL, lease_until = NULL, error = NULL WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()", [
                $message->id,
                $token,
            ]) !== 1
        ) {
            throw new RecoverableMessageHandlingException('Job claim expired before completion.', retryDelay: 60_000);
        }
    }

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

    public function work(string $receiver, bool $once = false): void
    {
        $transport = match ($receiver) {
            'outbox' => $this->outbox,
            'jobs' => $this->queue,
            default => throw new InvalidArgumentException('Use outbox or jobs.'),
        };
        $events = new EventDispatcher();
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new JobServices([$receiver => $transport]),
            new JobServices([$receiver => new MultiplierRetryStrategy(10, 1000, 2, 60_000, 0)]),
        ));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new JobServices([$receiver => $this->failed])));
        new Worker([$receiver => $transport], $this->bus, $events)->run(['stop_when_empty' => $once]);
    }
}

final readonly class JobServices implements ContainerInterface
{
    public function __construct(
        private array $services,
    ) {}

    public function get(string $id): mixed
    {
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\Authority\AuthorityJournal;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\Priority;
use Closure;
use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim;
use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Bundle\Messenger\EarlyResumeMiddleware;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Bundle\Transport\MessengerWorkflowTimerDispatcher;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\OutboxSender;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Wire Operations through upstream Durable and Messenger adapters under Tempest.
 *
 * The journal has its own connection. Departmental admission and publication
 * policy live outside this service graph; no Symfony kernel is started.
 */
final class DurableRuntime
{
    public FencedEventStoreInterface $events;
    public DbalWorkflowMetadataStore $metadata;
    public WorkflowRegistry $workflows;
    public RegistryActivityExecutor $activities;
    public MessengerWorkflowResumeDispatcher $dispatcher;
    public DoctrineTransport $queue;
    public DoctrineTransport $activityQueue;
    public MessageBus $bus;
    public DoctrineTransport $failed;
    private HandlersLocator $handlers;
    /** @var array<string, list<callable>> */
    private array $handlerMap;
    public DoctrineTransport $outbox;
    public DoctrineTransport $interactive;

    /**
     * Keep journal transactions separate from application and queue transactions.
     */
    public function __construct(
        Connection $journal,
        Connection $application,
        LockFactory $locks,
        int $visibilitySeconds = 1,
        ?Closure $activitySender = null,
        ?PublicationAuthority $authority = null,
        ?ActivityAttemptClaimInterface $claims = null,
    ) {
        $schema = new DurableSchema($journal);
        $schema->ensure();
        $events = new DbalEventStore($journal, $schema);
        $this->events = $authority === null ? $events : new AuthorityJournal($events, $authority);
        $this->metadata = new DbalWorkflowMetadataStore($journal, $schema);
        $links = new DbalChildWorkflowParentLinkStore($journal, $schema);
        $loader = new WorkflowDefinitionLoader();
        $this->workflows = new WorkflowRegistry($loader);
        $this->activities = new RegistryActivityExecutor();
        $serializer = new PhpSerializer();
        $this->queue = new DoctrineTransport(new TransportConnection(['queue_name' => 'durable_resumes', 'redeliver_timeout' => $visibilitySeconds], $application), $serializer);
        $this->activityQueue = new DoctrineTransport(new TransportConnection([
            'queue_name' => 'durable_activities',
            'redeliver_timeout' => $visibilitySeconds,
        ], $application), $serializer);
        $this->failed = new DoctrineTransport(new TransportConnection(['queue_name' => 'durable_failed'], $application), $serializer);
        $this->interactive = new DoctrineTransport(new TransportConnection([
            'queue_name' => 'operations_interactive',
            'redeliver_timeout' => $visibilitySeconds,
        ], $application), $serializer);
        $this->outbox = new DoctrineTransport(new TransportConnection(['queue_name' => 'operations_outbox', 'redeliver_timeout' => $visibilitySeconds], $application), $serializer);
        $this->queue->setup();
        $senders = new SendersLocator([ResumeWorkflowMessage::class => ['resumes'], StartAssignment::class => ['admissions']], new ServiceLocator([
            'resumes' => fn () => $this->queue,
            'admissions' => fn () => new OutboxSender($this->activityQueue, $this->outbox, 'admissions', 'outbox', 'failed'),
            'interactive' => fn () => new OutboxSender($this->interactive, $this->outbox, 'interactive', 'outbox', 'failed'),
        ]));
        // A lazy iterator resolves the dispatcher/handler construction cycle.
        $this->bus = new MessageBus(
            (function () use ($locks, $senders) {
                yield from [
                    new EarlyResumeMiddleware(),
                    new SingleResumeLockMiddleware($locks),
                    new DispatchAfterCurrentBusMiddleware(),
                    new SendMessageMiddleware($senders),
                ];
                yield new HandleMessageMiddleware($this->handlers);
            })(),
        );
        $this->dispatcher = new MessengerWorkflowResumeDispatcher($this->bus, $this->metadata, $senders);
        $transport = new MessengerActivityTransport(
            $activitySender === null ? $this->activityQueue : $activitySender($this->activityQueue, $this->interactive),
            $this->activityQueue,
        );
        $runtime = new ExecutionRuntime($this->events, $transport, $this->activities, maxActivityRetries: 3, distributed: true);
        $engine = new ExecutionEngine($this->events, $runtime, workflowDefinitionLoader: $loader);
        $processor = new ActivityMessageProcessor(
            $this->events,
            $transport,
            $this->activities,
            $this->dispatcher,
            new NullActivityHeartbeatSender(),
            maxRetries: 3,
            attemptClaim: $claims ?? new LockActivityAttemptClaim($locks, 1.0),
        );
        $this->handlerMap = [
            ActivityMessage::class => [new ActivityRunHandler($processor)],
            ResumeWorkflowMessage::class => [new ResumeWorkflowHandler(
                $engine,
                $this->workflows,
                $this->metadata,
                $this->dispatcher,
                $this->events,
                $links,
                new MessengerWorkflowTimerDispatcher($this->bus),
                $loader,
            )],
        ];
        $this->handlers = new HandlersLocator($this->handlerMap);
    }

    /**
     * Register host handlers without duplicating upstream message processing.
     */
    public function on(string $message, callable $handler): void
    {
        $this->handlerMap[$message] = [$handler];
        $this->handlers = new HandlersLocator($this->handlerMap);
    }

    /**
     * Store domain admission through Messenger's native transactional outbox.
     */
    public function admit(StartAssignment $message, Priority $priority): void
    {
        $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp([$priority === Priority::Interactive ? 'interactive' : 'admissions'])]));
    }

    /**
     * Consume using Messenger's worker; never use the adapter's eager-ack dequeue.
     *
     * Messenger retains handler failures using its native retry and failure listeners.
     */
    public function drain(?string $receiver = null, bool $once = true, ?int $limit = null): void
    {
        $transports = ['outbox' => $this->outbox, 'interactive' => $this->interactive, 'resumes' => $this->queue, 'activities' => $this->activityQueue];
        $events = new EventDispatcher();
        $senders = [];
        $strategies = [];
        $failures = [];
        foreach ($transports as $name => $transport) {
            $senders[$name] = static fn () => $transport;
            $strategies[$name] = static fn () => new MultiplierRetryStrategy(3, 100);
            $failures[$name] = fn () => $this->failed;
        }

        $events->addSubscriber(new SendFailedMessageForRetryListener(new ServiceLocator($senders), new ServiceLocator($strategies)));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator($failures)));
        if ($limit !== null) {
            $events->addSubscriber(new StopWorkerOnMessageLimitListener($limit));
        }

        $worker = new Worker($receiver === null ? $transports : [$receiver => $transports[$receiver]], $this->bus, $events);
        $signals = [];
        $asynchronous = null;
        if (function_exists('pcntl_async_signals')) {
            $asynchronous = pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                /** @var callable|int $previous */
                $previous = pcntl_signal_get_handler($signal);
                $signals[$signal] = $previous;
                pcntl_signal($signal, $worker->stop(...));
            }
        }

        try {
            $worker->run(['stop_when_empty' => $once]);
        } finally {
            foreach ($signals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($asynchronous !== null) {
                pcntl_async_signals($asynchronous);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Operations\Durable;

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
use Gplanchat\Durable\RegistryActivityExecutor;
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
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Wire the bounded spike through upstream adapters, without a Symfony kernel.
 *
 * The journal must have its own connection. This is experimental wiring, not
 * the departmental API: admission and canonical Vault write fencing are absent.
 */
final class DurableRuntime
{
    public DbalEventStore $events;
    public DbalWorkflowMetadataStore $metadata;
    public WorkflowRegistry $workflows;
    public RegistryActivityExecutor $activities;
    public MessengerWorkflowResumeDispatcher $dispatcher;
    public DoctrineTransport $queue;
    public DoctrineTransport $activityQueue;
    public MessageBus $bus;
    public DoctrineTransport $failed;
    private HandlersLocator $handlers;

    /**
     * Keep journal transactions separate from application and queue transactions.
     */
    public function __construct(Connection $journal, Connection $application, LockFactory $locks)
    {
        $schema = new DurableSchema($journal);
        $schema->ensure();
        $this->events = new DbalEventStore($journal, $schema);
        $this->metadata = new DbalWorkflowMetadataStore($journal, $schema);
        $links = new DbalChildWorkflowParentLinkStore($journal, $schema);
        $loader = new WorkflowDefinitionLoader();
        $this->workflows = new WorkflowRegistry($loader);
        $this->activities = new RegistryActivityExecutor();
        $serializer = new PhpSerializer();
        $this->queue = new DoctrineTransport(new TransportConnection(['queue_name' => 'durable_resumes', 'redeliver_timeout' => 1], $application), $serializer);
        $this->activityQueue = new DoctrineTransport(new TransportConnection(['queue_name' => 'durable_activities', 'redeliver_timeout' => 1], $application), $serializer);
        $this->failed = new DoctrineTransport(new TransportConnection(['queue_name' => 'durable_failed'], $application), $serializer);
        $this->queue->setup();
        $senders = new SendersLocator([ResumeWorkflowMessage::class => ['resumes']], new ServiceLocator(['resumes' => fn () => $this->queue]));
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
        $transport = new MessengerActivityTransport($this->activityQueue, $this->activityQueue);
        $runtime = new ExecutionRuntime($this->events, $transport, $this->activities, distributed: true);
        $engine = new ExecutionEngine($this->events, $runtime, workflowDefinitionLoader: $loader);
        $processor = new ActivityMessageProcessor(
            $this->events,
            $transport,
            $this->activities,
            $this->dispatcher,
            new NullActivityHeartbeatSender(),
            attemptClaim: new LockActivityAttemptClaim($locks, 1.0),
        );
        $this->handlers = new HandlersLocator([
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
        ]);
    }

    /**
     * Consume using Messenger's worker; never use the adapter's eager-ack dequeue.
     *
     * Messenger retains handler failures using its native retry and failure listeners.
     */
    public function drain(?string $receiver = null): void
    {
        $transports = ['resumes' => $this->queue, 'activities' => $this->activityQueue];
        $events = new EventDispatcher();
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['resumes' => fn () => $this->queue, 'activities' => fn () => $this->activityQueue]),
            new ServiceLocator(['resumes' => fn () => new MultiplierRetryStrategy(3, 100), 'activities' => fn () => new MultiplierRetryStrategy(3, 100)]),
        ));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator(['resumes' => fn () => $this->failed, 'activities' => fn () => $this->failed]),
        ));
        new Worker($receiver === null ? $transports : [$receiver => $transports[$receiver]], $this->bus, $events)->run(['stop_when_empty' => true]);
    }
}

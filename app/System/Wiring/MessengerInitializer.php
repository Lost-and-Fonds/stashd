<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Jobs\JobMessage;
use App\Jobs\JobMessageHandler;
use App\Jobs\MessengerTransportRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class MessengerInitializer implements Initializer
{
    public function initialize(Container $container): MessageBusInterface
    {
        $transports = $container->get(MessengerTransportRegistry::class);
        $senders = new MessengerServiceContainer($transports->all());
        $failureSenders = new MessengerServiceContainer([
            'interactive' => $transports->get('failed'),
            'background' => $transports->get('failed'),
        ]);
        $strategies = new MessengerServiceContainer([
            'interactive' => new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 1000),
            'background' => new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 5000),
        ]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($senders, $strategies));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener($failureSenders));

        $handlers = new HandlersLocator([
            JobMessage::class => [function (JobMessage $message) use ($container): void {
                ($container->get(JobMessageHandler::class))($message);
            }],
        ]);

        return new MessageBus([
            new SendMessageMiddleware(new SendersLocator([JobMessage::class => ['background']], $senders), $dispatcher),
            new HandleMessageMiddleware($handlers),
        ]);
    }
}

/** @internal */
final class MessengerServiceContainer implements \Psr\Container\ContainerInterface
{
    /** @param array<string, object> $values */
    public function __construct(private array $values) {}

    public function get(string $id): mixed
    {
        return $this->values[$id] ?? throw new \InvalidArgumentException("Unknown Messenger service: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->values[$id]);
    }
}

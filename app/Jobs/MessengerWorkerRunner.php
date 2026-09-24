<?php

declare(strict_types=1);

namespace App\Jobs;

use App\System\Wiring\MessengerServiceContainer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Worker;

final readonly class MessengerWorkerRunner
{
    public function __construct(private MessengerTransportRegistry $transports, private MessageBusInterface $bus, private JobLifecycleSubscriber $lifecycle) {}

    public function run(string $workload): void
    {
        $worker = $this->worker($workload);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function () use ($worker): void {
                $worker->keepalive(120);
                pcntl_alarm(60);
            });
            pcntl_alarm(60);
        }

        try {
            $worker->run(['sleep' => 1000000]);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
        }
    }

    private function worker(string $workload): Worker
    {
        if (! in_array($workload, ['interactive', 'background'], true)) {
            throw new \InvalidArgumentException("Unknown Messenger workload: {$workload}");
        }

        $senders = new MessengerServiceContainer($this->transports->all());
        $failures = new MessengerServiceContainer(['interactive' => $this->transports->get('failed'), 'background' => $this->transports->get('failed')]);
        $strategies = new MessengerServiceContainer([$workload => new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 5000)]);
        $events = new EventDispatcher();
        $events->addSubscriber($this->lifecycle);
        $events->addSubscriber(new SendFailedMessageForRetryListener($senders, $strategies));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener($failures));

        $worker = new Worker([$workload => $this->transports->get($workload)], $this->bus, $events);

        return $worker;
    }
}

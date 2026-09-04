<?php

declare(strict_types=1);

namespace App\Jobs;

use App\System\Event\EventPublisher;
use Tempest\EventBus\EventHandler;

final readonly class JobLifecycleChangedHandler
{
    public function __construct(private EventPublisher $publisher) {}

    #[EventHandler]
    public function publish(JobLifecycleChanged $event): void
    {
        match ($event->event) {
            'created' => $this->publisher->jobCreated($event->job),
            'completed' => $this->publisher->jobCompleted($event->job),
            'failed' => $this->publisher->jobFailed($event->job),
            default => $this->publisher->jobProgress($event->job),
        };
    }
}

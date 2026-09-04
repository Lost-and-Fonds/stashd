<?php

declare(strict_types=1);

namespace App\Jobs;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\EventBus\event;

final readonly class JobLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(private JobRepository $jobs) {}

    public function received(WorkerMessageReceivedEvent $event): void
    {
        $job = $this->job($event->getEnvelope()->getMessage());

        if ($job === null) {
            return;
        }

        $job->state = JobState::Processing;
        $job->startedAt ??= DateTime::now(Timezone::UTC);
        $job->attempts = max($job->attempts, RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope()) + 1);
        $this->jobs->save($job);
        event(new JobLifecycleChanged($job, 'running'));
    }

    public function handled(WorkerMessageHandledEvent $event): void
    {
        $job = $this->job($event->getEnvelope()->getMessage());

        if ($job === null) {
            return;
        }

        $job->state = JobState::Ready;
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        event(new JobLifecycleChanged($job, 'completed'));
    }

    public function failed(WorkerMessageFailedEvent $event): void
    {
        $job = $this->job($event->getEnvelope()->getMessage());

        if ($job === null || $event->willRetry()) {
            return;
        }

        $job->state = JobState::Failed;
        $job->lastError = $event->getThrowable()->getMessage();
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        event(new JobLifecycleChanged($job, 'failed'));
    }

    public function retried(WorkerMessageRetriedEvent $event): void
    {
        $job = $this->job($event->getEnvelope()->getMessage());

        if ($job === null) {
            return;
        }

        $job->state = JobState::Retrying;
        $job->lastError = $event->getEnvelope()->last(\Symfony\Component\Messenger\Stamp\ErrorDetailsStamp::class)?->getExceptionMessage();
        $this->jobs->save($job);
        event(new JobLifecycleChanged($job, 'retrying'));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'received',
            WorkerMessageHandledEvent::class => 'handled',
            WorkerMessageFailedEvent::class => 'failed',
            WorkerMessageRetriedEvent::class => 'retried',
        ];
    }

    private function job(object $message): ?JobRecord
    {
        return $message instanceof JobMessage && JobId::isValid($message->jobId)
            ? $this->jobs->find(JobId::parse($message->jobId))
            : null;
    }
}

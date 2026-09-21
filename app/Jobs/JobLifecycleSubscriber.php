<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Broadcasts\BroadcastItemRepository;
use App\Support\PrefixedUlid;
use App\Vault\ItemId;
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
    public function __construct(
        private JobRepository $jobs,
        private BroadcastItemRepository $broadcastItems,
        private JobDispatcher $dispatch,
    ) {}

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

        if (in_array($job->intent, ['core.acquire_assets', 'core.download_captions'], true)) {
            $this->scheduleSettledBroadcasts($job);
        } elseif ($job->intent === 'core.broadcast' && ($job->payload['rebuild_after_asset_acquisition'] ?? false) === true) {
            $this->scheduleMarkedBroadcast($job);
        }
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

    private function scheduleSettledBroadcasts(JobRecord $job): void
    {
        $payload = $job->payload ?? [];
        $rawItemId = $payload['item_id'] ?? $payload['media_item_id'] ?? null;

        if (! is_string($rawItemId) || $rawItemId === '') {
            return;
        }

        foreach ($this->broadcastItems->listForItem(ItemId::parse($rawItemId)) as $item) {
            $broadcastId = (string) $item->broadcast->id;

            if ($this->hasActiveAssetAcquisitions((string) $item->broadcast->stashId)) {
                continue;
            }

            $activeBroadcast = $this->jobs->pendingOrProcessing(JobType::core('core.broadcast'), PrefixedUlid::parse($broadcastId));

            if ($activeBroadcast !== null) {
                if ($activeBroadcast->state !== JobState::Pending) {
                    $activeBroadcast->payload = [...($activeBroadcast->payload ?? []), 'rebuild_after_asset_acquisition' => true];
                    $this->jobs->save($activeBroadcast);
                }

                continue;
            }

            $this->dispatchBroadcastRebuild($broadcastId, (string) $item->broadcast->stashId);
        }
    }

    private function scheduleMarkedBroadcast(JobRecord $job): void
    {
        $payload = $job->payload ?? [];
        $broadcastId = $payload['broadcast_id'] ?? $job->entityId;

        if (! is_string($broadcastId) || $broadcastId === '' || ! is_string($job->stashId) || $job->stashId === '') {
            return;
        }

        if ($this->hasActiveAssetAcquisitions($job->stashId)
            || $this->jobs->hasPendingOrProcessingEntity(JobType::core('core.broadcast'), 'broadcast', $broadcastId)) {
            return;
        }

        $this->dispatchBroadcastRebuild($broadcastId, $job->stashId);
    }

    private function dispatchBroadcastRebuild(string $broadcastId, string $stashId): void
    {
        if ($this->jobs->hasPendingOrProcessingEntity(JobType::core('core.broadcast'), 'broadcast', $broadcastId)) {
            return;
        }

        $this->dispatch->dispatch(
            'core.broadcast',
            entityType: 'broadcast',
            entityId: $broadcastId,
            stashId: $stashId,
            payload: ['broadcast_id' => $broadcastId, 'action' => 'rebuild'],
            workload: 'background',
        );
    }

    private function hasActiveAssetAcquisitions(string $stashId): bool
    {
        return $this->jobs->hasPendingOrProcessingInStash(JobType::core('core.acquire_assets'), $stashId)
            || $this->jobs->hasPendingOrProcessingInStash(JobType::core('core.download_captions'), $stashId);
    }
}

<?php

declare(strict_types=1);

namespace App\System\Activity;

use App\Broadcasts\BroadcastRecord;
use App\Downloads\DownloadExecutionResult;
use App\Jobs\JobRecord;
use App\Stashes\StashInputCommitResult;
use App\Stashes\StashInputRecord;
use App\Stashes\StashInputSyncResult;
use App\Stashes\StashRecord;
use App\System\Event\EventPublisher;
use App\System\Secret\SecretsService;

final readonly class ActivityEventService
{
    public function __construct(private ActivityEventRepository $events, private SecretsService $secrets, private EventPublisher $publisher) {}

    public function stashCreated(StashRecord $stash): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'stash.created', sprintf('Stash "%s" created.', $stash->name), 'stash', (string) $stash->id, stashId: (string) $stash->id);
    }
    public function stashUpdated(StashRecord $stash): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'stash.updated', sprintf('Stash "%s" updated.', $stash->name), 'stash', (string) $stash->id, stashId: (string) $stash->id);
    }
    public function stashDeleted(string $stashId): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'stash.deleted', 'Stash deleted.', 'stash', $stashId, stashId: $stashId);
    }
    public function inputUpdated(StashInputRecord $input): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'stash.input_updated', sprintf('Input "%s" updated.', $input->title ?? $input->sourceUri), 'stash_input', (string) $input->id, stashId: (string) $input->stashId);
    }
    public function broadcastCreated(BroadcastRecord $broadcast): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'broadcast.created', sprintf('Broadcast "%s" created.', $broadcast->name), 'broadcast', (string) $broadcast->id, stashId: (string) $broadcast->stashId, broadcastId: (string) $broadcast->id);
    }
    public function broadcastUpdated(BroadcastRecord $broadcast): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'broadcast.updated', sprintf('Broadcast "%s" updated.', $broadcast->name), 'broadcast', (string) $broadcast->id, stashId: (string) $broadcast->stashId, broadcastId: (string) $broadcast->id);
    }
    public function broadcastDeleted(BroadcastRecord $broadcast): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Info, 'broadcast.deleted', sprintf('Broadcast "%s" deleted.', $broadcast->name), 'broadcast', (string) $broadcast->id, stashId: (string) $broadcast->stashId, broadcastId: (string) $broadcast->id);
    }

    public function stashInputCommitted(?object $ignored, JobRecord $job, StashInputCommitResult $result): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Success, 'stash.input_added', sprintf('Input added to stash with %d new media items.', $result->mediaItemsCreated), 'stash', $result->stashId, stashId: $result->stashId, jobId: (string) $job->id, groupKey: 'job:' . (string) $job->id, metadata: $result->toArray());
    }

    public function stashInputSynced(?object $ignored, JobRecord $job, StashInputRecord $input, StashInputSyncResult $result): ActivityEventRecord
    {
        $label = $input->title ?? $input->sourceUri;
        $message = $result->stashItemsCreated > 0 ? sprintf('Found %d new item(s) in %s.', $result->stashItemsCreated, $label) : sprintf('No new items in %s.', $label);

        return $this->emit(ActivityLevel::Success, 'stash.input_synced', $message, 'stash', $result->stashId, stashId: $result->stashId, jobId: (string) $job->id, groupKey: 'job:' . (string) $job->id, metadata: $result->toArray());
    }

    public function storageCheckCompleted(JobRecord $job, bool $ok): ActivityEventRecord
    {
        return $this->emit($ok ? ActivityLevel::Success : ActivityLevel::Warning, 'storage_check.completed', $ok ? 'Storage check completed successfully.' : 'Storage check completed with warnings.', 'job', (string) $job->id, jobId: (string) $job->id, groupKey: 'job:' . (string) $job->id);
    }

    public function downloadCompleted(?object $ignored, JobRecord $job, DownloadExecutionResult $result): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Success, 'download.completed', $result->skipped ? 'Download skipped because Vault original already exists.' : sprintf('Download completed with %d ready assets.', $result->assetsReady), 'media_item', $result->mediaItemId, stashId: is_string($job->payload['stash_id'] ?? null) ? $job->payload['stash_id'] : null, mediaItemId: $result->mediaItemId, jobId: (string) $job->id, groupKey: 'job:' . (string) $job->id, metadata: $result->toArray());
    }

    public function downloadFailed(JobRecord $job, string $code, string $error): ActivityEventRecord
    {
        return $this->emit(ActivityLevel::Error, 'download.failed', $this->secrets->redact($error), 'job', (string) $job->id, stashId: is_string($job->payload['stash_id'] ?? null) ? $job->payload['stash_id'] : null, mediaItemId: is_string($job->payload['media_item_id'] ?? null) ? $job->payload['media_item_id'] : null, jobId: (string) $job->id, groupKey: 'job:' . (string) $job->id, metadata: ['code' => $code]);
    }

    /** @param array<string, mixed>|null $metadata */
    private function emit(ActivityLevel $level, string $type, string $message, ?string $entityType = null, ?string $entityId = null, ?string $stashId = null, ?string $mediaItemId = null, ?string $broadcastId = null, ?string $jobId = null, ?string $groupKey = null, ?array $metadata = null): ActivityEventRecord
    {
        $record = $this->events->create(level: $level, type: $type, message: $message, entityType: $entityType, entityId: $entityId, stashId: $stashId, mediaItemId: $mediaItemId, broadcastId: $broadcastId, jobId: $jobId, groupKey: $groupKey, metadata: $metadata);
        $this->publisher->activityCreated($record);

        return $record;
    }
}

<?php

declare(strict_types=1);

namespace App\System\Activity;

use App\Broadcasts\BroadcastId;
use App\Commands\CommandRecord;
use App\Jobs\JobRecord;
use App\Stashes\StashRecord;
use App\Support\PrefixedUlid;
use App\System\Event\EventPublisher;
use App\System\Secret\SecretsService;

final readonly class ActivityEventService
{
    public function __construct(
        private ActivityEventRepository $events,
        private SecretsService $secrets,
        private EventPublisher $publisher,
    ) {
    }

    public function commandAccepted(CommandRecord $command, bool $publish = true): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'command.accepted',
            message: sprintf('Command %s accepted.', $command->type->value),
            entityType: 'command',
            entityId: (string) $command->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            publish: $publish,
        );
    }

    public function stashCreated(StashRecord $stash): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'stash.created',
            message: sprintf('Stash "%s" created.', $stash->name),
            entityType: 'stash',
            entityId: (string) $stash->id,
            stashId: (string) $stash->id,
        );
    }

    public function jobStarted(JobRecord $job): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'job.started',
            message: sprintf('Job %s started.', $job->intent->value),
            entityType: 'job',
            entityId: (string) $job->id,
            jobId: (string) $job->id,
            commandId: $job->commandId?->toString(),
            groupKey: $job->commandId === null ? 'job:' . (string) $job->id : 'command:' . $job->commandId,
        );
    }

    public function preflightCompleted(CommandRecord $command, JobRecord $job, int $itemCount): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'preflight.completed',
            message: sprintf('Preflight completed with %d estimated items.', $itemCount),
            entityType: 'command',
            entityId: (string) $command->id,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['estimated_item_count' => $itemCount],
        );
    }

    public function storageCheckCompleted(JobRecord $job, bool $ok): ActivityEventRecord
    {
        return $this->emit(
            level: $ok ? ActivityLevel::Success : ActivityLevel::Warning,
            type: 'storage_check.completed',
            message: $ok ? 'Storage check completed successfully.' : 'Storage check completed with warnings.',
            entityType: 'job',
            entityId: (string) $job->id,
            jobId: (string) $job->id,
            commandId: $job->commandId?->toString(),
            groupKey: $job->commandId === null ? 'job:' . (string) $job->id : 'command:' . $job->commandId,
        );
    }

    public function jobFailed(JobRecord $job, string $error): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Error,
            type: 'job.failed',
            message: $this->secrets->redact($error),
            entityType: 'job',
            entityId: (string) $job->id,
            jobId: (string) $job->id,
            commandId: $job->commandId?->toString(),
            groupKey: $job->commandId === null ? 'job:' . (string) $job->id : 'command:' . $job->commandId,
        );
    }

    public function commandCompleted(CommandRecord $command): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'command.completed',
            message: sprintf('Command %s completed.', $command->type->value),
            entityType: 'command',
            entityId: (string) $command->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
        );
    }

    public function commandFailed(CommandRecord $command, string $error): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Error,
            type: 'command.failed',
            message: $this->secrets->redact($error),
            entityType: 'command',
            entityId: (string) $command->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
        );
    }

    public function stashInputCommitted(
        CommandRecord                       $command,
        JobRecord                           $job,
        \App\Stashes\StashInputCommitResult $result,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'stash.input_added',
            message: sprintf(
                'Input added to stash with %d new media items.',
                $result->mediaItemsCreated,
            ),
            entityType: 'stash',
            entityId: $result->stashId,
            stashId: $result->stashId,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: $result->toArray(),
        );
    }

    public function stashInputSynced(
        CommandRecord                     $command,
        JobRecord                         $job,
        \App\Stashes\StashInputRecord     $input,
        \App\Stashes\StashInputSyncResult $result,
    ): ActivityEventRecord {
        $label = $input->title ?? $input->sourceUri;

        return $this->emit(
            level: ActivityLevel::Success,
            type: 'stash.input_synced',
            message: $result->stashItemsCreated > 0
                ? sprintf('Found %d new item(s) in %s.', $result->stashItemsCreated, $label)
                : sprintf('No new items in %s.', $label),
            entityType: 'stash',
            entityId: $result->stashId,
            stashId: $result->stashId,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: $result->toArray(),
        );
    }

    public function retriedFailedDownloads(
        CommandRecord $command,
        JobRecord $job,
        string $stashId,
        int $retriedCount,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'stash.retried_failed',
            message: sprintf('Retried %d failed download(s).', $retriedCount),
            entityType: 'stash',
            entityId: $stashId,
            stashId: $stashId,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
        );
    }

    public function downloadCompleted(
        CommandRecord $command,
        JobRecord $job,
        \App\Downloads\DownloadExecutionResult $result,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'download.completed',
            message: $result->skipped
                ? 'Download skipped because Vault original already exists.'
                : sprintf('Download completed with %d ready assets.', $result->assetsReady),
            entityType: 'media_item',
            entityId: $result->mediaItemId,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: $result->toArray(),
        );
    }

    public function downloadFailed(JobRecord $job, string $code, string $error): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Error,
            type: 'download.failed',
            message: $this->secrets->redact($error),
            entityType: 'job',
            entityId: (string) $job->id,
            jobId: (string) $job->id,
            commandId: $job->commandId?->toString(),
            groupKey: $job->commandId === null ? 'job:' . (string) $job->id : 'command:' . $job->commandId,
            metadata: ['code' => $code],
        );
    }

    /** @param array<string, mixed> $result */
    public function vaultVerifyCompleted(CommandRecord $command, JobRecord $job, array $result): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'vault.verify_completed',
            message: 'Vault verification completed.',
            entityType: 'command',
            entityId: (string) $command->id,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: $result,
        );
    }

    /** @param array<string, mixed> $plan */
    public function broadcastPlanned(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $plan,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'broadcast.planned',
            message: sprintf('Broadcast planned with %d files.', (int) ($plan['file_count'] ?? 0)),
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['file_count' => (int) ($plan['file_count'] ?? 0)],
        );
    }

    public function broadcastRebuildStarted(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'broadcast.rebuild_started',
            message: 'Broadcast rebuild started.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
        );
    }

    /** @param array<string, mixed> $publish */
    public function broadcastPublished(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $publish,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'broadcast.published',
            message: sprintf('Broadcast published %d files.', (int) ($publish['published_count'] ?? 0)),
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['published_count' => (int) ($publish['published_count'] ?? 0)],
        );
    }

    /** @param array<string, mixed> $verify */
    public function broadcastVerified(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $verify,
    ): ActivityEventRecord {
        return $this->emit(
            level: ($verify['ok'] ?? false) ? ActivityLevel::Success : ActivityLevel::Warning,
            type: 'broadcast.verified',
            message: ($verify['ok'] ?? false)
                ? 'Broadcast verification succeeded.'
                : 'Broadcast verification found stale or missing files.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: [
                'valid_count' => (int) ($verify['valid_count'] ?? 0),
                'stale_count' => (int) ($verify['stale_count'] ?? 0),
            ],
        );
    }

    /** @param array<string, mixed> $verify */
    public function broadcastStale(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $verify,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Warning,
            type: 'broadcast.stale',
            message: 'Broadcast is stale and needs regeneration.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: [
                'stale_count' => (int) ($verify['stale_count'] ?? 0),
            ],
        );
    }

    public function broadcastFailed(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        string $code,
        string $error,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Error,
            type: 'broadcast.failed',
            message: $this->secrets->redact($error),
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['code' => $code],
        );
    }

    /** @param array<string, mixed> $result */
    public function broadcastTokenRotated(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $result,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'broadcast.token_rotated',
            message: 'Podcast broadcast token rotated.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: [
                'token_preview' => (string) ($result['token_preview'] ?? ''),
                'revoked_old_secret' => (bool) ($result['revoked_old_secret'] ?? false),
            ],
        );
    }

    /** @param array<string, mixed> $prune */
    public function broadcastPruned(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $prune,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Info,
            type: 'broadcast.pruned',
            message: sprintf('Broadcast pruned %d stale files.', (int) ($prune['removed_count'] ?? 0)),
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['removed_count' => (int) ($prune['removed_count'] ?? 0)],
        );
    }

    /** @param array<string, mixed> $status */
    public function mediaServerTestCompleted(
        CommandRecord $command,
        JobRecord $job,
        PrefixedUlid $connectionId,
        array $status,
    ): ActivityEventRecord {
        return $this->emit(
            level: ($status['ok'] ?? false) ? ActivityLevel::Success : ActivityLevel::Warning,
            type: 'media_server.test_completed',
            message: $this->secrets->redact((string) ($status['message'] ?? 'Media server test completed.')),
            entityType: 'media_server_connection',
            entityId: $connectionId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: ['ok' => (bool) ($status['ok'] ?? false)],
        );
    }

    /** @param array<string, mixed> $trigger */
    public function broadcastTriggerSucceeded(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $trigger,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'broadcast.trigger_succeeded',
            message: 'Media server scan trigger succeeded.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: [
                'success_count' => (int) ($trigger['success_count'] ?? 0),
            ],
        );
    }

    /** @param array<string, mixed> $trigger */
    public function broadcastTriggerFailed(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $trigger,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Warning,
            type: 'broadcast.trigger_failed',
            message: 'Media server scan trigger failed; broadcast files remain valid.',
            entityType: 'broadcast',
            entityId: $broadcastId->toString(),
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: [
                'failure_count' => (int) ($trigger['failure_count'] ?? 0),
            ],
        );
    }

    public function podcastAudioTranscodeCompleted(
        CommandRecord $command,
        JobRecord $job,
        \App\Transcoding\TranscodePodcastAudioResult $result,
    ): ActivityEventRecord {
        return $this->emit(
            level: ActivityLevel::Success,
            type: 'asset.transcode_completed',
            message: 'Podcast audio transcode completed.',
            entityType: 'media_item',
            entityId: $result->mediaItemId,
            jobId: (string) $job->id,
            commandId: (string) $command->id,
            groupKey: 'command:' . (string) $command->id,
            metadata: $result->toArray(),
        );
    }

    public function podcastAudioTranscodeFailed(JobRecord $job, string $code, string $error): ActivityEventRecord
    {
        return $this->emit(
            level: ActivityLevel::Error,
            type: 'asset.transcode_failed',
            message: $this->secrets->redact($error),
            entityType: 'job',
            entityId: (string) $job->id,
            jobId: (string) $job->id,
            commandId: $job->commandId?->toString(),
            groupKey: $job->commandId === null ? 'job:' . (string) $job->id : 'command:' . $job->commandId,
            metadata: ['code' => $code],
        );
    }

    /** @param array<string, mixed>|null $metadata */
    private function emit(
        ActivityLevel $level,
        string $type,
        string $message,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $stashId = null,
        ?string $mediaItemId = null,
        ?string $broadcastId = null,
        ?string $jobId = null,
        ?string $commandId = null,
        ?string $groupKey = null,
        ?array $metadata = null,
        bool $publish = true,
    ): ActivityEventRecord {
        $record = $this->events->create(
            level: $level,
            type: $type,
            message: $message,
            entityType: $entityType,
            entityId: $entityId,
            stashId: $stashId,
            mediaItemId: $mediaItemId,
            broadcastId: $broadcastId,
            jobId: $jobId,
            commandId: $commandId,
            groupKey: $groupKey,
            metadata: $metadata,
        );

        if ($publish) {
            $this->publisher->activityCreated($record);
        }

        return $record;
    }
}

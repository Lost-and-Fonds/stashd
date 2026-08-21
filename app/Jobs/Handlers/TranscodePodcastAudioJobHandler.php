<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastItemRepository;
use App\Commands\CommandDispatchService;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Support\PrefixedUlid;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use App\Transcoding\Ffmpeg\FfmpegProgress;
use App\Transcoding\TranscodeException;
use App\Transcoding\TranscodePodcastAudioAsset;
use App\Vault\AssetId;
use App\Vault\MediaItemId;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\DateTime\Timezone;

final readonly class TranscodePodcastAudioJobHandler implements JobHandler
{
    public function __construct(
        private TranscodePodcastAudioAsset $executor,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private BroadcastItemRepository $broadcastItems,
        private CommandDispatchService $dispatch,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {
    }

    public function intent(): JobIntent
    {
        return JobIntent::TranscodePodcastAudio;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofPercent(0.0, 'Preparing transcode'));

        $payload = $job->payload ?? [];

        $mediaItemId = MediaItemId::parse((string) ($payload['media_item_id'] ?? ''));
        $sourceAssetId = AssetId::parse((string) ($payload['source_asset_id'] ?? ''));
        $audioAssetId = AssetId::parse((string) ($payload['asset_id'] ?? ''));

        $lastForwardedAt = microtime(true);

        try {
            $result = $this->executor->execute(
                mediaItemId: $mediaItemId,
                sourceAssetId: $sourceAssetId,
                audioAssetId: $audioAssetId,
                jobId: PrefixedUlid::parse((string) $job->id),
                onProgress: function (FfmpegProgress $progress) use ($job, $context, &$lastForwardedAt): void {
                    // Keep the UI live without persisting every ffmpeg callback.
                    // The final update always forwards regardless.
                    $now = microtime(true);
                    $isFinal = $progress->percent >= 100.0;

                    if (! $isFinal && $now - $lastForwardedAt < 0.25) {
                        return;
                    }

                    $lastForwardedAt = $now;
                    $context->heartbeat($job);
                    $context->progress($job, JobProgressUpdate::ofPercent(
                        percent: $progress->percent,
                        label: sprintf('Transcoding: %d%%', (int) $progress->percent),
                        etaSeconds: $progress->etaSeconds,
                    ));
                },
            );
        } catch (TranscodeException $exception) {
            // Without this, a real transcode failure leaves affected broadcasts
            // stuck reporting "pending" forever -- nothing else re-verifies them.
            // JobWorkerService::runJob still does the job/command failure
            // bookkeeping once this rethrows.
            $this->retriggerAffectedBroadcasts((string) $mediaItemId);

            throw $exception;
        }

        $command->result = $result->toArray();
        $this->commands->save($command);

        $job->progressPercent = 100.0;
        $job->progressLabel = 'Podcast audio ready';
        $job->progressEtaSeconds = Duration::zero();
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofPercent(100.0, $job->progressLabel, 0));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->podcastAudioTranscodeCompleted($command, $job, $result);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);

        $this->retriggerAffectedBroadcasts((string) $mediaItemId);
    }

    /** Rebuild every affected broadcast after a derived asset changes. */
    private function retriggerAffectedBroadcasts(string $mediaItemId): void
    {
        $broadcastIds = [];

        foreach ($this->broadcastItems->listForMediaItem(MediaItemId::parse($mediaItemId)) as $item) {
            $broadcastIds[(string) $item->broadcast->id] = true;
        }

        foreach (array_keys($broadcastIds) as $broadcastId) {
            $this->dispatch->dispatch(CommandType::BroadcastRebuild, ['broadcast_id' => $broadcastId]);
        }
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new RuntimeException('Transcode job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new RuntimeException('Transcode command not found.');
    }
}

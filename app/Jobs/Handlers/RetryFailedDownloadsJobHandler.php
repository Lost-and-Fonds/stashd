<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

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
use App\Stashes\CreateStashFromDiscovery;
use App\Stashes\StashId;
use App\Stashes\StashItemRepository;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

/**
 * Same fan-out shape as {@see CreateStashFromDiscovery}'s
 * auto-download loop: dispatches one independent item.download command per
 * failed item, each individually tracked/retryable, rather than this job
 * owning their downloads directly (a command's job-completion lifecycle
 * assumes one job per command; download jobs already do too, per
 * {@see DownloadJobHandler}).
 */
final readonly class RetryFailedDownloadsJobHandler implements JobHandler
{
    public function __construct(
        private StashItemRepository $stashItems,
        private MediaItemRepository $mediaItems,
        private CommandDispatchService $commandDispatch,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::RetryFailedDownloads;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Finding failed items'));

        $payload = $job->payload ?? [];
        $stashIdRaw = $payload['stash_id'] ?? '';
        $stashId = StashId::parse(is_string($stashIdRaw) ? $stashIdRaw : '');

        $stashItems = $this->stashItems->listForStash($stashId);
        $mediaItemIds = array_values(array_unique(array_map(
            static fn($item): string => (string) $item->mediaItemId,
            $stashItems,
        )));
        $mediaItemsById = $this->mediaItems->listByIds($mediaItemIds);

        $retriedCount = 0;

        foreach ($stashItems as $stashItem) {
            $mediaItem = $mediaItemsById[(string) $stashItem->mediaItemId] ?? null;

            if ($mediaItem === null || $mediaItem->state !== MediaItemState::Failed) {
                continue;
            }

            $this->commandDispatch->dispatch(CommandType::ItemDownload, [
                'media_item_id' => (string) $stashItem->mediaItemId,
                'stash_id' => $stashId->toString(),
            ]);
            $retriedCount++;
        }

        $command->result = ['retried_count' => $retriedCount];
        $command->targetType = 'stash';
        $command->targetId = $stashId->toString();
        $this->commands->save($command);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = "Retried {$retriedCount} failed item(s)";
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->retriedFailedDownloads($command, $job, $stashId->toString(), $retriedCount);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new RuntimeException('Retry-failed-downloads job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new RuntimeException('Retry-failed-downloads command not found.');
    }
}

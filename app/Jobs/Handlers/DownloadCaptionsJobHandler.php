<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastItemRepository;
use App\Commands\CommandDispatchService;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Downloads\DownloadCaptions;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Support\PrefixedUlid;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use App\Vault\MediaItemId;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class DownloadCaptionsJobHandler implements JobHandler
{
    public function __construct(private DownloadCaptions $captions, private CommandRepository $commands, private JobRepository $jobs, private StateTransitionService $transitions, private BroadcastItemRepository $broadcastItems, private CommandDispatchService $dispatch, private EventPublisher $publisher) {}

    public function intent(): JobIntent
    {
        return JobIntent::DownloadCaptions;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Caption job is missing commandId.');
        }
        $command = $this->commands->find($job->commandId) ?? throw new \RuntimeException('Caption command not found.');
        $this->transitions->transitionCommand($command, CommandState::Running);
        $payload = $job->payload ?? [];
        $mediaItemId = is_string($payload['media_item_id'] ?? null) ? $payload['media_item_id'] : '';
        $languages = is_string($payload['languages'] ?? null) ? $payload['languages'] : 'en';
        $this->captions->execute(MediaItemId::parse($mediaItemId), PrefixedUlid::parse((string) $job->id), $languages, ($payload['include_auto'] ?? false) === true);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Captions downloaded';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->publisher->jobCompleted($job);

        foreach ($this->broadcastItems->listForMediaItem(MediaItemId::parse($mediaItemId)) as $item) {
            $this->dispatch->dispatch(CommandType::BroadcastRebuild, ['broadcast_id' => (string) $item->broadcast->id]);
        }
    }
}

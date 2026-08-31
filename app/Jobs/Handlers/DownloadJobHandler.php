<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Http\Api\ApiJson;
use App\Downloads\DownloadException;
use App\Downloads\DownloadMediaItem;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Stashes\StashId;
use App\Support\PrefixedUlid;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use App\Vault\MediaItemId;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\DateTime\Timezone;

final readonly class DownloadJobHandler implements JobHandler
{
    public function __construct(
        private DownloadMediaItem $executor,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::Download;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofPercent(0.0, 'Preparing download'));

        $payload = $job->payload ?? [];

        $mediaItemId = MediaItemId::parse(ApiJson::string($payload['media_item_id'] ?? null));
        $stashId = StashId::parse(ApiJson::string($payload['stash_id'] ?? null));
        $force = (bool) ($payload['force'] ?? false);

        try {
            $result = $this->executor->execute(
                mediaItemId: $mediaItemId,
                stashId: $stashId,
                jobId: PrefixedUlid::parse((string) $job->id),
                force: $force,
                onProgress: function (?string $stage = null) use ($context, $job): void {
                    if ($stage === null || $stage === '') {
                        $context->heartbeat($job);

                        return;
                    }

                    $context->progress($job, JobProgressUpdate::indeterminate($stage));
                },
            );

            $command->result = $result->toArray();
            $this->commands->save($command);

            $job->progressPercent = 100.0;
            $job->progressLabel = $result->skipped ? 'Download skipped (already in Vault)' : 'Download complete';
            $job->progressEtaSeconds = Duration::zero();
            $job->finishedAt = DateTime::now(Timezone::UTC);
            $this->jobs->save($job);
            $context->progress($job, JobProgressUpdate::ofPercent(100.0, $job->progressLabel, 0));

            $this->transitions->transitionJob($job, JobState::Ready);
            $this->transitions->transitionCommand($command, CommandState::Completed);
            $this->activity->downloadCompleted($command, $job, $result);
            $this->publisher->jobCompleted($job);
            $this->activity->commandCompleted($command);
        } catch (DownloadException $exception) {
            throw $exception;
        }
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Download job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new \RuntimeException('Download command not found.');
    }
}

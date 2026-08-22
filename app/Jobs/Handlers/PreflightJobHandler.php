<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Config\StashdConfig;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Stashes\DiscoverStashInput;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class PreflightJobHandler implements JobHandler
{
    public function __construct(
        private DiscoverStashInput $executor,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
        private StashdConfig $config,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::Preflight;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Running preflight'));

        $payload = $job->payload ?? [];

        $result = $this->executor->execute($payload);
        $reviewUrl = rtrim($this->config->publicUrl, '/') . '/api/v1/stashes/preflight/' . (string) $command->id . '/review';
        $resultArray = $result->toResultArray($reviewUrl);

        $command->result = $resultArray;
        $this->commands->save($command);

        $job->progressCurrent = $result->estimatedItemCount;
        $job->progressTotal = max(1, $result->estimatedItemCount);
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Preflight complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps($job->progressCurrent, $job->progressTotal, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->preflightCompleted($command, $job, $result->estimatedItemCount);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Preflight job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new \RuntimeException('Preflight command not found.');
    }
}

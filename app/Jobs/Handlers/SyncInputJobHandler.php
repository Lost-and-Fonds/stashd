<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Stashes\StashInputId;
use App\Stashes\StashInputRepository;
use App\Stashes\SyncStashInput;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class SyncInputJobHandler implements JobHandler
{
    public function __construct(
        private SyncStashInput $sync,
        private StashInputRepository $stashInputs,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::SyncInput;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Checking for new items'));

        $payload = $job->payload ?? [];
        $rawInputId = $payload['stash_input_id'] ?? null;

        if (! is_string($rawInputId) || ! StashInputId::isValid($rawInputId)) {
            throw new RuntimeException('Sync job is missing a valid stash_input_id.');
        }

        $input = $this->stashInputs->find(StashInputId::parse($rawInputId))
            ?? throw new RuntimeException('Sync job targets an input that no longer exists.');

        $result = $this->sync->execute($input);

        $command->result = $result->toArray();
        $this->commands->save($command);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = $result->stashItemsCreated > 0
            ? sprintf('Added %d new item(s)', $result->stashItemsCreated)
            : 'No new items';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->stashInputSynced($command, $job, $input, $result);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new RuntimeException('Sync job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new RuntimeException('Sync command not found.');
    }
}

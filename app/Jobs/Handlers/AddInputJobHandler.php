<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Commands\CommandId;
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
use App\Stashes\CreateStashFromDiscovery;
use App\Stashes\StashId;
use App\Stashes\StashRepository;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class AddInputJobHandler implements JobHandler
{
    public function __construct(
        private CreateStashFromDiscovery $stashFromPreflight,
        private StashRepository $stashes,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::AddInput;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Adding input to stash'));

        $payload = $job->payload ?? [];

        $stashId = StashId::parse((string) ($payload['stash_id'] ?? ''));
        $stash = $this->stashes->find($stashId)
            ?? throw new RuntimeException('Add-input job targets a stash that no longer exists.');

        $preflightCommandId = (string) ($payload['preflight_command_id'] ?? '');
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $preflightCommand = $this->commands->find(CommandId::parse($preflightCommandId))
            ?? throw new RuntimeException('Preflight command not found.');
        $result = $this->stashFromPreflight->commitInput($stash, $preflightCommand, $options);

        $command->result = $result->toArray();
        $command->targetType = 'stash';
        $command->targetId = $result->stashId;
        $this->commands->save($command);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Input added to stash';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->stashInputCommitted($command, $job, $result);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new RuntimeException('Add-input job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new RuntimeException('Add-input command not found.');
    }
}

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
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\Health\HealthService;
use App\System\State\StateTransitionService;
use App\System\Storage\StorageCapabilityChecker;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class StorageCheckJobHandler implements JobHandler
{
    public function __construct(
        private StorageCapabilityChecker $storageChecks,
        private HealthService $health,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::StorageCheck;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->optionalCommand($job);

        if ($command !== null) {
            $this->transitions->transitionCommand($command, CommandState::Running);
        }

        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(0, 2, 'Checking storage roots'));

        $this->storageChecks->checkAll();
        $context->heartbeat($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Evaluating health report'));

        $report = $this->health->report();
        $ok = $report->status === 'ok';

        $result = [
            'status' => $report->status,
            'storage' => $report->toDetailedArray()['storage'] ?? [],
        ];

        if ($command !== null) {
            $command->result = $result;
            $this->commands->save($command);
        }

        $job->progressCurrent = 2;
        $job->progressTotal = 2;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Storage check complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);

        $this->transitions->transitionJob($job, JobState::Ready);

        if ($command !== null) {
            $this->transitions->transitionCommand($command, CommandState::Completed);
            $this->activity->commandCompleted($command);
        }

        $this->activity->storageCheckCompleted($job, $ok);
        $this->publisher->jobCompleted($job);
    }

    private function optionalCommand(JobRecord $job): ?CommandRecord
    {
        if ($job->commandId === null) {
            return null;
        }

        return $this->commands->find($job->commandId);
    }
}

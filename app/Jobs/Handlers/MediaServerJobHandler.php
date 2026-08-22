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
use App\MediaServers\MediaServerConnectionService;
use App\MediaServers\MediaServerException;
use App\Support\PrefixedUlid;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class MediaServerJobHandler implements JobHandler
{
    public function __construct(
        private MediaServerConnectionService $connections,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {
    }

    public function intent(): JobIntent
    {
        return JobIntent::MediaServer;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);

        $payload = $job->payload ?? [];

        $connectionId = PrefixedUlid::parse((string) ($payload['media_server_connection_id'] ?? ''));
        $action = (string) ($payload['action'] ?? 'test_connection');

        $job->progressTotal = 2;
        $this->jobs->save($job);

        $result = match ($action) {
            'test_connection' => $this->handleTestConnection($command, $job, $context, $connectionId),
            'list_libraries' => $this->handleListLibraries($command, $job, $context, $connectionId),
            default => throw MediaServerException::withCode('media_server_action_unsupported', 'Unsupported media server action.'),
        };

        $command->result = $result;
        $this->commands->save($command);

        $job->progressCurrent = 2;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Plugin operation ' . $action . ' complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->commandCompleted($command);
        $this->publisher->jobCompleted($job);
    }

    /** @return array<string, mixed> */
    private function handleTestConnection(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        PrefixedUlid $connectionId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Testing media server connection'));
        $status = $this->connections->testConnection($connectionId);
        $this->activity->mediaServerTestCompleted($command, $job, $connectionId, $status);

        return ['status' => $status];
    }

    /** @return array<string, mixed> */
    private function handleListLibraries(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        PrefixedUlid $connectionId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Listing media server libraries'));
        $libraries = $this->connections->listLibraries($connectionId);

        return [
            'libraries' => $libraries,
        ];
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Media server job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new \RuntimeException('Media server command not found.');
    }
}

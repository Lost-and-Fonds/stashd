<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\BroadcastState;
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
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Throwable;

final readonly class BroadcastJobHandler implements JobHandler
{
    public function __construct(
        private BroadcastLifecycleService $lifecycle,
        private BroadcastRepository $broadcasts,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::Broadcast;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);

        $payload = $job->payload ?? [];

        $broadcastId = BroadcastId::parse($this->requiredPayloadString($payload, 'broadcast_id'));
        $action = $this->requiredPayloadString($payload, 'action');

        $broadcast = $this->broadcasts->find($broadcastId);

        $job->progressTotal = match ($action) {
            'plan', 'verify', 'prune', 'delete', 'trigger', 'rotate_token' => 2,
            'rebuild', 'rebuild_item' => null,
            default => 1,
        };
        $this->jobs->save($job);

        try {
            $result = match ($action) {
                'plan' => $this->handlePlan($command, $job, $context, $broadcastId),
                'rebuild' => $this->handleRebuild($command, $job, $context, $broadcastId),
                'rebuild_item' => $this->handleRebuildItem($command, $job, $context, $broadcastId, BroadcastItemId::parse($this->requiredPayloadString($payload, 'broadcast_item_id'))),
                'verify' => $this->handleVerify($command, $job, $context, $broadcastId),
                'prune' => $this->handlePrune($command, $job, $context, $broadcastId),
                'delete' => $this->handleDelete($job, $context, $broadcastId),
                'trigger' => $this->handleTrigger($command, $job, $context, $broadcastId),
                'rotate_token' => $this->handleRotateToken($command, $job, $context, $broadcastId),
                default => throw BroadcastException::withCode('broadcast_action_unsupported', 'Unsupported broadcast action.'),
            };

            $command->result = $result;
            $this->commands->save($command);

            $job->progressCurrent = $job->progressTotal;
            $job->progressPercent = 100.0;
            $job->progressLabel = 'Broadcast ' . $action . ' complete';
            $job->finishedAt = DateTime::now(Timezone::UTC);
            $this->jobs->save($job);
            $context->progress(
                $job,
                $job->progressTotal === null
                    ? JobProgressUpdate::ofPercent(100.0, $job->progressLabel)
                    : JobProgressUpdate::ofSteps($job->progressTotal, $job->progressTotal, $job->progressLabel),
            );

            $this->transitions->transitionJob($job, JobState::Ready);
            $this->transitions->transitionCommand($command, CommandState::Completed);

            if ($action === 'delete' && $broadcast !== null) {
                $this->activity->broadcastDeleted($broadcast);
            }
            $this->activity->commandCompleted($command);
            $this->publisher->jobCompleted($job);
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof BroadcastException ? $exception->errorCode : 'broadcast_failed';

            if ($broadcast !== null && $broadcast->state === BroadcastState::Processing) {
                $broadcast->lastError = $errorCode;
                $this->broadcasts->save($broadcast);

                if ($broadcast->state->canTransitionTo(BroadcastState::Failed)) {
                    $this->transitions->transitionBroadcast($broadcast, BroadcastState::Failed);
                }
            }

            $this->activity->broadcastFailed($command, $job, $broadcastId, $errorCode, $exception->getMessage());

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function handlePlan(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Planning broadcast'));
        $plan = $this->lifecycle->plan($broadcastId);
        $this->activity->broadcastPlanned($command, $job, $broadcastId, $plan->toArray());
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast plan ready'));

        return ['plan' => $plan->toArray()];
    }

    /** @return array<string, mixed> */
    private function handleRebuild(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $this->activity->broadcastRebuildStarted($command, $job, $broadcastId);

        $result = $this->lifecycle->rebuild(
            $broadcastId,
            fn(string $label) => $context->progress($job, JobProgressUpdate::indeterminate($label)),
        );
        $this->activity->broadcastPublished($command, $job, $broadcastId, $result->publish ?? []);

        $this->activity->broadcastVerified($command, $job, $broadcastId, $result->verify ?? []);

        if (($result->verify['stale_count'] ?? 0) > 0) {
            $this->activity->broadcastStale($command, $job, $broadcastId, $result->verify ?? []);
        }

        if ($result->trigger !== null) {
            $this->recordTriggerActivity($command, $job, $broadcastId, $result->trigger);
        }

        return $result->toArray();
    }

    /** @return array<string, mixed> */
    private function handleRebuildItem(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
        BroadcastItemId $broadcastItemId,
    ): array {
        $this->activity->broadcastRebuildStarted($command, $job, $broadcastId);
        $result = $this->lifecycle->rebuildItem(
            $broadcastItemId,
            fn(string $label) => $context->progress($job, JobProgressUpdate::indeterminate($label)),
        );
        $this->activity->broadcastPublished($command, $job, $broadcastId, $result->publish ?? []);

        return $result->toArray();
    }

    /** @return array<string, mixed> */
    private function handleTrigger(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Running broadcast operation'));
        $trigger = $this->lifecycle->trigger($broadcastId);
        $this->recordTriggerActivity($command, $job, $broadcastId, $trigger->toArray());
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast operation complete'));

        return ['trigger' => $trigger->toArray()];
    }

    /** @param array<string, mixed> $trigger */
    private function recordTriggerActivity(
        CommandRecord $command,
        JobRecord $job,
        BroadcastId $broadcastId,
        array $trigger,
    ): void {
        if (($trigger['failure_count'] ?? 0) > 0) {
            $this->activity->broadcastOperationFailed($command, $job, $broadcastId, $trigger);
        } elseif (($trigger['success_count'] ?? 0) > 0) {
            $this->activity->broadcastOperationSucceeded($command, $job, $broadcastId, $trigger);
        }
    }

    /** @return array<string, mixed> */
    private function handleRotateToken(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Executing broadcast plugin action'));
        $result = $this->lifecycle->invokePluginAction($broadcastId, 'rotate_token');
        $this->activity->broadcastPluginActionCompleted($command, $job, $broadcastId, 'rotate_token', $result);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast plugin action complete'));

        return ['token' => $result];
    }

    /** @return array<string, mixed> */
    private function handleVerify(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Verifying broadcast'));
        $verify = $this->lifecycle->verify($broadcastId);
        $this->activity->broadcastVerified($command, $job, $broadcastId, $verify->toArray());

        if ($verify->staleCount > 0) {
            $this->activity->broadcastStale($command, $job, $broadcastId, $verify->toArray());
        }

        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast verification complete'));

        return ['verify' => $verify->toArray()];
    }

    /** @return array<string, mixed> */
    private function handlePrune(
        CommandRecord $command,
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Pruning stale broadcast files'));
        $prune = $this->lifecycle->prune($broadcastId);
        $this->activity->broadcastPruned($command, $job, $broadcastId, $prune->toArray());
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast prune complete'));

        return ['prune' => $prune->toArray()];
    }

    /** @return array<string, mixed> */
    private function handleDelete(
        JobRecord $job,
        JobHandlerContext $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Removing generated broadcast files'));
        $deleted = $this->lifecycle->delete($broadcastId);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast deleted'));

        return ['delete' => $deleted->toArray()];
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Broadcast job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new \RuntimeException('Broadcast command not found.');
    }

    /** @param array<string, mixed> $payload */
    private function requiredPayloadString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw BroadcastException::withCode('broadcast_payload_invalid', 'Broadcast job payload is invalid.');
        }

        return $value;
    }
}

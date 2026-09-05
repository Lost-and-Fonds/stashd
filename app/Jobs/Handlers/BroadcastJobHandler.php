<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\BroadcastState;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\System\Activity\ActivityEventService;
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Throwable;

final readonly class BroadcastJobHandler implements JobHandler
{
    public function __construct(
        private BroadcastLifecycleService $lifecycle,
        private BroadcastRepository $broadcasts,
        private JobRepository $jobs,
        private ActivityEventService $activity,
        private StateTransitionService $transitions,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $payload = $job->payload ?? [];

        $broadcastId = BroadcastId::parse($this->requiredPayloadString($payload, 'broadcast_id'));
        $action = $this->requiredPayloadString($payload, 'action');

        $broadcast = $this->broadcasts->find($broadcastId);

        $job->progressTotal = match ($action) {
            'plan', 'verify', 'prune', 'delete', 'rotate_token' => 2,
            'rebuild', 'rebuild_item' => null,
            default => 1,
        };
        $this->jobs->save($job);

        try {
            $result = match ($action) {
                'plan' => $this->handlePlan($job, $context, $broadcastId),
                'rebuild' => $this->handleRebuild($job, $context, $broadcastId),
                'rebuild_item' => $this->handleRebuildItem($job, $context, $broadcastId, BroadcastItemId::parse($this->requiredPayloadString($payload, 'broadcast_item_id'))),
                'verify' => $this->handleVerify($job, $context, $broadcastId),
                'prune' => $this->handlePrune($job, $context, $broadcastId),
                'delete' => $this->handleDelete($job, $context, $broadcastId),
                'rotate_token' => $this->handleRotateToken($job, $context, $broadcastId),
                default => throw BroadcastException::withCode('broadcast_action_unsupported', 'Unsupported broadcast action.'),
            };

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

            if ($action === 'delete' && $broadcast !== null) {
                $this->activity->broadcastDeleted($broadcast);
            }
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof BroadcastException ? $exception->errorCode : 'broadcast_failed';

            if ($broadcast !== null && $broadcast->state === BroadcastState::Processing) {
                $broadcast->lastError = $errorCode;
                $this->broadcasts->save($broadcast);

                if ($broadcast->state->canTransitionTo(BroadcastState::Failed)) {
                    $this->transitions->transitionBroadcast($broadcast, BroadcastState::Failed);
                }
            }


            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function handlePlan(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Planning broadcast'));
        $plan = $this->lifecycle->plan($broadcastId);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast plan ready'));

        return ['plan' => $plan->toArray()];
    }

    /** @return array<string, mixed> */
    private function handleRebuild(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {

        $result = $this->lifecycle->rebuild(
            $broadcastId,
            fn(string $label, ?float $fraction = null) => $context->progress($job, $this->rebuildProgress($label, $fraction)),
        );


        return $result->toArray();
    }

    private function rebuildProgress(string $label, ?float $fraction = null): JobProgressUpdate
    {
        if ($fraction !== null) {
            return JobProgressUpdate::ofPercent($fraction * 100, $label);
        }

        return match ($label) {
            'Planning broadcast' => JobProgressUpdate::ofPercent(10.0, $label),
            'Publishing broadcast' => JobProgressUpdate::ofPercent(50.0, $label),
            'Verifying broadcast' => JobProgressUpdate::ofPercent(90.0, $label),
            default => JobProgressUpdate::indeterminate($label),
        };
    }

    /** @return array<string, mixed> */
    private function handleRebuildItem(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
        BroadcastItemId $broadcastItemId,
    ): array {
        $result = $this->lifecycle->rebuildItem(
            $broadcastItemId,
            fn(string $label) => $context->progress($job, JobProgressUpdate::indeterminate($label)),
        );

        return $result->toArray();
    }

    /** @return array<string, mixed> */
    private function handleRotateToken(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Executing broadcast plugin action'));
        $result = $this->lifecycle->invokePluginAction($broadcastId, 'rotate_token');
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast plugin action complete'));

        return ['token' => $result];
    }

    /** @return array<string, mixed> */
    private function handleVerify(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Verifying broadcast'));
        $verify = $this->lifecycle->verify($broadcastId);

        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast verification complete'));

        return ['verify' => $verify->toArray()];
    }

    /** @return array<string, mixed> */
    private function handlePrune(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Pruning stale broadcast files'));
        $prune = $this->lifecycle->prune($broadcastId);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast prune complete'));

        return ['prune' => $prune->toArray()];
    }

    /** @return array<string, mixed> */
    private function handleDelete(
        JobRecord $job,
        JobProgressReporter $context,
        BroadcastId $broadcastId,
    ): array {
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Removing generated broadcast files'));
        $deleted = $this->lifecycle->delete($broadcastId);
        $context->progress($job, JobProgressUpdate::ofSteps(2, 2, 'Broadcast deleted'));

        return ['delete' => $deleted->toArray()];
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

<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastRepository;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class ExternalBroadcastOperationJobHandler implements JobHandler
{
    public function __construct(
        private BroadcastLifecycleService $lifecycle,
        private BroadcastRepository $broadcasts,
        private JobRepository $jobs,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $progress): void
    {
        $payload = $job->payload ?? [];
        $broadcastId = $payload['broadcast_id'] ?? null;
        $operation = $payload['operation'] ?? null;

        if (! is_string($broadcastId) || ! is_string($operation) || $broadcastId === '' || $operation === '') {
            throw new RuntimeException('Plugin broadcast job is missing its broadcast or operation.');
        }

        if ($this->broadcasts->find(BroadcastId::parse($broadcastId)) === null) {
            throw new RuntimeException('Plugin broadcast job targets a broadcast that no longer exists.');
        }

        $progress->progress($job, JobProgressUpdate::indeterminate('Running ' . str_replace('_', ' ', $operation)));

        $this->lifecycle->invokePluginAction(BroadcastId::parse($broadcastId), $operation);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Broadcast operation complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $progress->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));
    }
}

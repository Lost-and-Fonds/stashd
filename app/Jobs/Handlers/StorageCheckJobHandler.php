<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\System\Activity\ActivityEventService;
use App\System\Health\HealthService;
use App\System\Storage\StorageCapabilityChecker;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class StorageCheckJobHandler implements JobHandler
{
    public function __construct(
        private StorageCapabilityChecker $storageChecks,
        private HealthService $health,
        private JobRepository $jobs,
        private ActivityEventService $activity,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $context->progress($job, JobProgressUpdate::ofSteps(0, 2, 'Checking storage roots'));

        $this->storageChecks->checkAll();
        $context->progress($job, JobProgressUpdate::ofSteps(1, 2, 'Evaluating health report'));

        $report = $this->health->report();
        $ok = $report->status === 'ok';

        $result = [
            'status' => $report->status,
            'storage' => $report->toDetailedArray()['storage'] ?? [],
        ];


        $job->progressCurrent = 2;
        $job->progressTotal = 2;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Storage check complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);



        $this->activity->storageCheckCompleted($job, $ok);
    }

}

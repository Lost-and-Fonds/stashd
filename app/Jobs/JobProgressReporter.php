<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\DurationSeconds;

use function Tempest\EventBus\event;

final readonly class JobProgressReporter
{
    public function __construct(private JobRepository $jobs) {}

    public function progress(JobRecord $job, JobProgressUpdate $update): void
    {
        $job->progressCurrent = $update->current;
        $job->progressTotal = $update->total;
        $job->progressPercent = $update->percent;
        $job->progressLabel = $update->label;
        $job->progressEtaSeconds = DurationSeconds::toDuration($update->etaSeconds);
        $job->progressRate = $update->rate;
        $this->jobs->save($job);
        event(new JobLifecycleChanged($job, 'progress'));
    }
}

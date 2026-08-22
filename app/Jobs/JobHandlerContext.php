<?php

declare(strict_types=1);

namespace App\Jobs;

final readonly class JobHandlerContext
{
    public function __construct(
        private JobWorkerCallbacks $callbacks,
    ) {}

    public function heartbeat(JobRecord $job): void
    {
        $this->callbacks->heartbeat($job);
    }

    public function progress(JobRecord $job, JobProgressUpdate $update): void
    {
        $this->callbacks->progress($job, $update);
    }
}

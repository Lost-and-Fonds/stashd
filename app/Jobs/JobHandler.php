<?php

declare(strict_types=1);

namespace App\Jobs;

interface JobHandler
{
    public function handle(JobRecord $job, JobProgressReporter $progress): void;
}

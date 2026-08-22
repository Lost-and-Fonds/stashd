<?php

declare(strict_types=1);

namespace App\Jobs;

interface JobHandler
{
    public function handle(JobRecord $job, JobHandlerContext $context): void;

    public function intent(): JobIntent;
}

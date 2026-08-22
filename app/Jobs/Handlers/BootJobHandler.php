<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Jobs\JobState;
use App\System\State\StateTransitionService;

final readonly class BootJobHandler implements JobHandler
{
    public function __construct(
        private StateTransitionService $transitions,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::Boot;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $context->heartbeat($job);
        $this->transitions->transitionJob($job, JobState::Ready);
    }
}

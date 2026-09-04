<?php

declare(strict_types=1);

namespace App\Jobs;

final readonly class JobLifecycleChanged
{
    public function __construct(
        public JobRecord $job,
        public string $event,
    ) {}
}

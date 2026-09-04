<?php

declare(strict_types=1);

namespace App\Jobs;

final readonly class JobDefinition
{
    /** @param class-string $handler */
    public function __construct(
        public JobType $type,
        public string $handler,
        public string $workload = 'background',
    ) {}
}

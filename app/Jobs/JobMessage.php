<?php

declare(strict_types=1);

namespace App\Jobs;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'background')]
final readonly class JobMessage
{
    public function __construct(
        public string $jobId,
        public string $type,
        public string $workload = 'background',
    ) {}
}

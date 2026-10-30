<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Job started waiting.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobWaitStarted
{
    /**
     * Records that the Job started waiting.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $waitId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

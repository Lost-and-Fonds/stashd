<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Job actually reached its paused state.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobPaused
{
    /**
     * Records that the Job actually reached its paused state.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $pauseId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

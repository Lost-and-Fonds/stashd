<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Acceptance records a promise to pause safely; it does not mean the Job is already paused.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobPauseAccepted
{
    /**
     * Acceptance records a promise to pause safely; it does not mean the Job is already paused.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $pauseId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

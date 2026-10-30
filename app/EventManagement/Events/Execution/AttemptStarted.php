<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Attempt started.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class AttemptStarted
{
    /**
     * Records that the Attempt started.
     *
     * @param string $jobId Logical durable Job.
     * @param string $attemptId Fenced physical execution episode.
     */
    public function __construct(
        public string $jobId,
        public string $attemptId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

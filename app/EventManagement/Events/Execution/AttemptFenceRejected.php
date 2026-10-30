<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a stale or unauthorized Attempt was rejected from exercising current Job authority.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class AttemptFenceRejected
{
    /**
     * Records that a stale or unauthorized Attempt was rejected from exercising current Job authority.
     *
     * @param string $jobId Logical durable Job.
     * @param string $attemptId Fenced physical execution episode.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $jobId,
        public string $attemptId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that cancellation actually reached its defined terminal condition.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobCancelled
{
    /**
     * Records that cancellation actually reached its defined terminal condition.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $cancellationId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records the job queued observation for the identified operation.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobQueued
{
    /**
     * Records the job queued observation for the identified operation.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $queueName,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

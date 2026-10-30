<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified dependency no longer blocks the Job.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkDependencyUnblocked
{
    /**
     * Records that the identified dependency no longer blocks the Job.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $dependencyJobId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a Job cannot proceed until the identified dependency Job is satisfied.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkDependencyBlocked
{
    /**
     * Records that a Job cannot proceed until the identified dependency Job is satisfied.
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

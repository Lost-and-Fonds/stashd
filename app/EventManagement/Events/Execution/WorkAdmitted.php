<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Work was admitted.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkAdmitted
{
    /**
     * Records that the Work was admitted.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

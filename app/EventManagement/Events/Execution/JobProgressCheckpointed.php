<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Job made a progress checkpoint.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
#[TransientObservation]
final readonly class JobProgressCheckpointed
{
    /**
     * Records that the Job made a progress checkpoint.
     *
     * @param string $jobId Logical durable Job.
     * @param string $checkpointId Durably saved continuation position for this operation.
     */
    public function __construct(
        public string $jobId,
        public string $checkpointId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

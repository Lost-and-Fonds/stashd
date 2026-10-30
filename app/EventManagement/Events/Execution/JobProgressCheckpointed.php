<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Job made a progress checkpoint.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[TransientObservation]
final readonly class JobProgressCheckpointed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $checkpointId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

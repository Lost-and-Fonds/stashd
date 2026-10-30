<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Acceptance records a promise to pause safely; it does not mean the Job is already paused.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class JobPauseAccepted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $pauseId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

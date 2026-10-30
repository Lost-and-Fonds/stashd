<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a request to pause a Job; it does not mean the request was accepted or the Job is paused.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class JobPauseRequested
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

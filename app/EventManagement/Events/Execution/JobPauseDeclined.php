<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a pause request was declined; it does not imply cancellation.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class JobPauseDeclined
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $reasonCode,
        public string $pauseId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

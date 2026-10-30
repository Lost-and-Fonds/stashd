<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a stale or unauthorized Attempt was rejected from exercising current Job authority.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class AttemptFenceRejected
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $attemptId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records expiration of an Attempt lease. It does not mean the logical Job itself expired.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class AttemptLeaseExpired
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $attemptId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

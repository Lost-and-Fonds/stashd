<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that incoming Work was coalesced with the identified existing Job under the same canonical Work key.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class WorkCoalesced
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $coalescedJobId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

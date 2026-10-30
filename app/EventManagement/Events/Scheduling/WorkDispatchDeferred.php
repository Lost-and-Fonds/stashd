<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Work was deferred for dispatch.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class WorkDispatchDeferred
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

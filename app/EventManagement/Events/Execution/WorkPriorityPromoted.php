<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records increased priority on an existing queued Job without creating a second execution.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class WorkPriorityPromoted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public int $previousPriority,
        public int $priority,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

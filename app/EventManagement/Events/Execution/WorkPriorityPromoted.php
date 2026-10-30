<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records increased priority on an existing queued Job without creating a second execution.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkPriorityPromoted
{
    /**
     * Records increased priority on an existing queued Job without creating a second execution.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public int $previousPriority,
        public int $priority,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Scheduled Work became due.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class ScheduledWorkDue
{
    /**
     * Records that the Scheduled Work became due.
     */
    public function __construct(
        public string $scheduleId,
        public string $workKey,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

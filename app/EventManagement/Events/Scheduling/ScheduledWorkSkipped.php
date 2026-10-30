<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Scheduled Work was skipped.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class ScheduledWorkSkipped
{
    /**
     * Records that the Scheduled Work was skipped.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $scheduleId,
        public string $workKey,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

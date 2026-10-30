<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Work was skipped for dispatch.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class WorkDispatchSkipped
{
    /**
     * Records that the Work was skipped for dispatch.
     *
     * @param string $jobId Logical durable Job.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

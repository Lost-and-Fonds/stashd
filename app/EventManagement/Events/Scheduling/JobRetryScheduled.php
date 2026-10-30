<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;
use DateTimeImmutable;

/**
 * Records that the Job was scheduled for retry.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class JobRetryScheduled
{
    /**
     * Records that the Job was scheduled for retry.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public DateTimeImmutable $retryAt,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

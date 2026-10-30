<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that scheduling dispatched the identified Job for execution admission.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class WorkDispatched
{
    /**
     * Records that scheduling dispatched the identified Job for execution admission.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Completion means this Job's required outcome was met; it does not alone prove preservation.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class JobCompleted
{
    /**
     * Completion means this Job's required outcome was met; it does not alone prove preservation.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Work Follow Up Recorded follow-up Work was recorded.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkFollowUpRecorded
{
    /**
     * Records that the Work Follow Up Recorded follow-up Work was recorded.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $followUpJobId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

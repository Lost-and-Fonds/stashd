<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that incoming Work was coalesced with the identified existing Job under the same canonical Work key.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkCoalesced
{
    /**
     * Records that incoming Work was coalesced with the identified existing Job under the same canonical Work key.
     *
     * @param string $jobId Logical durable Job.
     */
    public function __construct(
        public string $jobId,
        public string $workKey,
        public string $coalescedJobId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified Worker became available as real execution capacity.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkerStarted
{
    /**
     * Records that the identified Worker became available as real execution capacity.
     *
     * @param string $workerId Execution capacity identity, independent of any assigned Job.
     */
    public function __construct(
        public string $workerId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

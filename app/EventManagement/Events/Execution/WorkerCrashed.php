<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Execution;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records an unexpected end of the identified Worker process.
 * Job, Attempt and Worker identities have separate authority; lifecycle observations do not prove preservation.
 */
final readonly class WorkerCrashed
{
    /**
     * Records an unexpected end of the identified Worker process.
     *
     * @param string $workerId Execution capacity identity, independent of any assigned Job.
     */
    public function __construct(
        public string $workerId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

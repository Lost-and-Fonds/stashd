<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Discovery resumed.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryRunResumed
{
    /**
     * Records that the Discovery resumed.
     *
     * @param string $attemptId Fenced physical execution episode.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $attemptId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

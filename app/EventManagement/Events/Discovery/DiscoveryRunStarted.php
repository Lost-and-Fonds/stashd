<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Discovery started.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryRunStarted
{
    /**
     * Records that the Discovery started.
     */
    public function __construct(
        public string $discoveryRunId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

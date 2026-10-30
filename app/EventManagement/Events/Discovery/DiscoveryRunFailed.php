<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Discovery failed.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryRunFailed
{
    /**
     * Records that the Discovery failed.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

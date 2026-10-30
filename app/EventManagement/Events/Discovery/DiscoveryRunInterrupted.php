<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a discovery execution stopped without claiming exhaustive refresh coverage.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryRunInterrupted
{
    /**
     * Records that a discovery execution stopped without claiming exhaustive refresh coverage.
     */
    public function __construct(
        public string $discoveryRunId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

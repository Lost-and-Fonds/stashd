<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\DiscoveryCoverage;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the logical discovery run completed with the declared exhaustive, partial or indeterminate coverage.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryRunCompleted
{
    /**
     * Records that the logical discovery run completed with the declared exhaustive, partial or indeterminate coverage.
     */
    public function __construct(
        public string $discoveryRunId,
        public DiscoveryCoverage $coverage,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

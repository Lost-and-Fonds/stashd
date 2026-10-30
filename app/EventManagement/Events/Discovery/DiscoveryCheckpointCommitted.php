<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Discovery was committed.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
#[TransientObservation]
final readonly class DiscoveryCheckpointCommitted
{
    /**
     * Records that the Discovery was committed.
     *
     * @param string $checkpointId Durably saved continuation position for this operation.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $checkpointId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

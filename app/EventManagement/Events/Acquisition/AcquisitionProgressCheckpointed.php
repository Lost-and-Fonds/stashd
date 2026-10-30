<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition made a progress checkpoint.
 * Gathering source material does not establish an authoritative Vault commit.
 */
#[TransientObservation]
final readonly class AcquisitionProgressCheckpointed
{
    /**
     * Records that the Acquisition made a progress checkpoint.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $checkpointId Durably saved continuation position for this operation.
     * @param list<string> $completedComponentIds Identified completed component ids for this occurrence.
     */
    public function __construct(
        public string $acquisitionId,
        public string $checkpointId,
        public array $completedComponentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

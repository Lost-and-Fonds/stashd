<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records continuation of the same logical acquisition from its saved checkpoint.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionResumed
{
    /**
     * Records continuation of the same logical acquisition from its saved checkpoint.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $checkpointId Durably saved continuation position for this operation.
     */
    public function __construct(
        public string $acquisitionId,
        public string $checkpointId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

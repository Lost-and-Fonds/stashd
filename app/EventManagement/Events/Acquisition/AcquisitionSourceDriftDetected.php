<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition source drift was detected.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionSourceDriftDetected
{
    /**
     * Records that the Acquisition source drift was detected.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     */
    public function __construct(
        public string $acquisitionId,
        public string $sourceId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

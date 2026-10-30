<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition was selected as an acquisition source.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionSourceSelected
{
    /**
     * Records that the Acquisition was selected as an acquisition source.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     */
    public function __construct(
        public string $acquisitionId,
        public string $sourceId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

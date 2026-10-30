<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Reacquisition started.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class ReacquisitionStarted
{
    /**
     * Records that the Reacquisition started.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $parentAcquisitionId Earlier acquisition whose material motivated this new acquisition.
     */
    public function __construct(
        public string $acquisitionId,
        public string $parentAcquisitionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

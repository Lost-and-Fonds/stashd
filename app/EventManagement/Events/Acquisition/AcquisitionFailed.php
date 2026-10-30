<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition failed.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionFailed
{
    /**
     * Records that the Acquisition failed.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $acquisitionId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

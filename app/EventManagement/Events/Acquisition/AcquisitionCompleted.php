<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records completion against the sealed declared acquisition scope, not everything that may ever exist upstream.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionCompleted
{
    /**
     * Records completion against the sealed declared acquisition scope, not everything that may ever exist upstream.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     */
    public function __construct(
        public string $acquisitionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

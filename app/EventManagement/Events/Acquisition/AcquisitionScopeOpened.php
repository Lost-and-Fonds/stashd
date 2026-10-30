<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition was opened.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionScopeOpened
{
    /**
     * Records that the Acquisition was opened.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     */
    public function __construct(
        public string $acquisitionId,
        public string $scopeId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a component added to the expected acquisition set. An estimated size is not an exact byte count.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class ExpectedComponentDeclared
{
    /**
     * Records a component added to the expected acquisition set. An estimated size is not an exact byte count.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $componentId Stable component identity known before authoritative OCFL version allocation.
     */
    public function __construct(
        public string $acquisitionId,
        public string $componentId,
        public ?int $sizeBytes,
        public bool $sizeIsEstimate,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

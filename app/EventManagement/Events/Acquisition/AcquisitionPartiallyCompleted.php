<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records acquisition completion with the identified known gaps; it does not erase successfully preserved partial material.
 * Gathering source material does not establish an authoritative Vault commit.
 */
final readonly class AcquisitionPartiallyCompleted
{
    /**
     * Records acquisition completion with the identified known gaps; it does not erase successfully preserved partial material.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param list<string> $missingComponentIds Identified missing component ids for this occurrence.
     */
    public function __construct(
        public string $acquisitionId,
        public array $missingComponentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

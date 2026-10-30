<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incidents were merged.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentsMerged
{
    /**
     * Records that the Incidents were merged.
     *
     * @param list<string> $sourceIncidentIds Identified source incident ids for this occurrence.
     */
    public function __construct(
        public string $incidentId,
        public array $sourceIncidentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

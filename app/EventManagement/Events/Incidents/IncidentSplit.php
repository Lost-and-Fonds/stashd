<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incident was split.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentSplit
{
    /**
     * Records that the Incident was split.
     *
     * @param list<string> $newIncidentIds Identified new incident ids for this occurrence.
     */
    public function __construct(
        public string $incidentId,
        public array $newIncidentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

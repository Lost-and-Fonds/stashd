<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records a change to the set of references affected by the Incident.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentScopeChanged
{
    /**
     * Records a change to the set of references affected by the Incident.
     *
     * @param list<string> $scopeReferences Identified scope references for this occurrence.
     */
    public function __construct(
        public string $incidentId,
        public array $scopeReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

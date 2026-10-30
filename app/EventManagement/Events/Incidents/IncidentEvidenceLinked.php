<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incident evidence was linked.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentEvidenceLinked
{
    /**
     * Records that the Incident evidence was linked.
     */
    public function __construct(
        public string $incidentId,
        public string $evidenceId,
        public string $evidenceReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

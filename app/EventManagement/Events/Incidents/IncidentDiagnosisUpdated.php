<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records a revised diagnosis code without changing the original Incident evidence.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentDiagnosisUpdated
{
    /**
     * Records a revised diagnosis code without changing the original Incident evidence.
     */
    public function __construct(
        public string $incidentId,
        public string $diagnosisCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

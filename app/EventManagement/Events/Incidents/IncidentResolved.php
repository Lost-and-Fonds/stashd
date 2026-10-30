<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Resolution follows verified recovery and does not rewrite the evidence that opened the Incident.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentResolved
{
    /**
     * Resolution follows verified recovery and does not rewrite the evidence that opened the Incident.
     */
    public function __construct(
        public string $incidentId,
        public string $recoveryId,
        public string $verificationReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

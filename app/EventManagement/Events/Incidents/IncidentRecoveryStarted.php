<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incident recovery started.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentRecoveryStarted
{
    /**
     * Records that the Incident recovery started.
     */
    public function __construct(
        public string $incidentId,
        public string $recoveryId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

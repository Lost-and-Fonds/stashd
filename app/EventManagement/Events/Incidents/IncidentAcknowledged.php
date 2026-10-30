<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Acknowledgement records operator receipt, not recovery or resolution.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentAcknowledged
{
    /**
     * Acknowledgement records operator receipt, not recovery or resolution.
     */
    public function __construct(
        public string $incidentId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

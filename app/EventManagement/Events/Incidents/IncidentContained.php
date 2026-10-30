<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incident was contained.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class IncidentContained
{
    /**
     * Records that the Incident was contained.
     */
    public function __construct(
        public string $incidentId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

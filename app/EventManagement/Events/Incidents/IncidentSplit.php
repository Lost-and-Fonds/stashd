<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Incident was split.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
final readonly class IncidentSplit
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $newIncidentIds Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $incidentId,
        public array $newIncidentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

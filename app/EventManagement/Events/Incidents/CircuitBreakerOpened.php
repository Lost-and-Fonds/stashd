<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Circuit Breaker was opened.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class CircuitBreakerOpened
{
    /**
     * Records that the Circuit Breaker was opened.
     */
    public function __construct(
        public string $breakerId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

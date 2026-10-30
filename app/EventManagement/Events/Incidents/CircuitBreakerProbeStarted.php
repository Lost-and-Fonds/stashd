<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Incidents;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the protective circuit breaker began a recovery probe.
 * An Incident evolves independently of immutable evidence and of protective circuit breaker authority.
 */
#[DurableHistory]
final readonly class CircuitBreakerProbeStarted
{
    /**
     * Records that the protective circuit breaker began a recovery probe.
     */
    public function __construct(
        public string $breakerId,
        public string $probeId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

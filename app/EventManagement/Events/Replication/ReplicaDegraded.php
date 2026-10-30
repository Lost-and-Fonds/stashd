<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Replica was degraded.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ReplicaDegraded
{
    /**
     * Records that the Replica was degraded.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $replicaId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Replication started.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ReplicationStarted
{
    /**
     * Records that the Replication started.
     */
    public function __construct(
        public string $replicaId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

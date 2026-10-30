<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records the replica registered observation for the identified operation.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ReplicaRegistered
{
    /**
     * Records the replica registered observation for the identified operation.
     */
    public function __construct(
        public string $replicaId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

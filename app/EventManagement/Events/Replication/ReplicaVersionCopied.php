<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Replica was copied.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ReplicaVersionCopied
{
    /**
     * Records that the Replica was copied.
     */
    public function __construct(
        public string $replicaId,
        public string $objectId,
        public string $version,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

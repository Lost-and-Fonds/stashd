<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records independent verification of a copied Vault object version, separate from copying it.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ReplicaVersionVerified
{
    /**
     * Records independent verification of a copied Vault object version, separate from copying it.
     */
    public function __construct(
        public string $replicaId,
        public string $objectId,
        public string $version,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

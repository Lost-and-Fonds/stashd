<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records independent verification of a copied Vault object version, separate from copying it.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class ReplicaVersionVerified
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $replicaId,
        public string $objectId,
        public string $version,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

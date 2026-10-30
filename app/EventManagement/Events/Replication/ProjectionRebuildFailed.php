<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified projection rebuild failed for the supplied reason.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ProjectionRebuildFailed
{
    /**
     * Records that the identified projection rebuild failed for the supplied reason.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $rebuildId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

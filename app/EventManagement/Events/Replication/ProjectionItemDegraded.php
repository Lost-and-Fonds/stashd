<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Projection Item was degraded.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ProjectionItemDegraded
{
    /**
     * Records that the Projection Item was degraded.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $rebuildId,
        public string $itemId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

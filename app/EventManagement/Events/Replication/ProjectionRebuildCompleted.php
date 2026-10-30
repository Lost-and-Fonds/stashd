<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the projection rebuild met its required evidence and completeness checks.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ProjectionRebuildCompleted
{
    /**
     * Records that the projection rebuild met its required evidence and completeness checks.
     */
    public function __construct(
        public string $rebuildId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records the start of the identified database projection rebuild.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class ProjectionRebuildStarted
{
    /**
     * Records the start of the identified database projection rebuild.
     */
    public function __construct(
        public string $rebuildId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

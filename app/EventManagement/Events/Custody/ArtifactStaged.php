<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * Staging records received bytes only; it does not establish durable custody or Vault commitment.
 * A custody receipt accepts responsibility for bytes; a later OCFL commit has its own identity and event.
 */
final readonly class ArtifactStaged
{
    /**
     * Staging records received bytes only; it does not establish durable custody or Vault commitment.
     *
     * @param string $acquisitionId Logical acquisition being observed.
     * @param string $componentId Stable component identity known before authoritative OCFL version allocation.
     */
    public function __construct(
        public string $artifactId,
        public string $acquisitionId,
        public string $componentId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

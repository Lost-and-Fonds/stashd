<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * Staging records received bytes only; it does not establish durable custody or Vault commitment.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ArtifactStaged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $artifactId,
        public string $acquisitionId,
        public string $componentId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

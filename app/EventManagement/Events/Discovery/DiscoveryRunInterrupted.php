<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that a discovery execution stopped without claiming exhaustive refresh coverage.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class DiscoveryRunInterrupted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $discoveryRunId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

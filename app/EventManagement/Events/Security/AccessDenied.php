<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Security;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records a real denied operation and its safe actor, resource and reason references.
 * Only safe identifying references belong here; credential values and authentication headers must never be included.
 */
#[DurableHistory]
final readonly class AccessDenied
{
    /**
     * Records a real denied operation and its safe actor, resource and reason references.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $actorId,
        public string $operation,
        public string $resourceReference,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

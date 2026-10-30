<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Security;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records a real denied operation and its safe actor, resource and reason references.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
#[DurableHistory]
final readonly class AccessDenied
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $actorId,
        public string $operation,
        public string $resourceReference,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

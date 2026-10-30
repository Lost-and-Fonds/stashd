<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that an organisational Stash was removed. This does not delete Items or preserved content.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class StashRemoved
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $stashId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Item selection changed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ItemSelectionChanged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $stashId,
        public bool $selected,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

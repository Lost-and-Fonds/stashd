<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * The caller must provide positive evidence for this Item/source relationship; absence from a result is insufficient.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class SourceUnavailabilityConfirmed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $inputId,
        public string $itemId,
        public string $evidenceReference,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * The caller must provide positive evidence for this Item/source relationship; absence from a result is insufficient.
 * An Input is a generic source mechanism; these references do not assume a URL or HTTP provider.
 */
final readonly class SourceUnavailabilityConfirmed
{
    /**
     * The caller must provide positive evidence for this Item/source relationship; absence from a result is insufficient.
     *
     * @param string $inputId Input through which the source relationship is known.
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $inputId,
        public string $itemId,
        public string $evidenceReference,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

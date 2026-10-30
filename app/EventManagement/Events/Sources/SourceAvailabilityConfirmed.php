<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified Item was confirmed available through the identified Input, based on positive evidence.
 * An Input is a generic source mechanism; these references do not assume a URL or HTTP provider.
 */
final readonly class SourceAvailabilityConfirmed
{
    /**
     * Records that the identified Item was confirmed available through the identified Input, based on positive evidence.
     *
     * @param string $inputId Input through which the source relationship is known.
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $inputId,
        public string $itemId,
        public string $evidenceReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

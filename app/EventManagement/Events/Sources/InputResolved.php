<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Input was resolved.
 * An Input is a generic source mechanism; these references do not assume a URL or HTTP provider.
 */
final readonly class InputResolved
{
    /**
     * Records that the Input was resolved.
     *
     * @param string $inputId Input through which the source relationship is known.
     */
    public function __construct(
        public string $inputId,
        public string $resolvedReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

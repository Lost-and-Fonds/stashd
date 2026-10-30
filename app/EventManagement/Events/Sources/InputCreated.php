<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Input was created.
 * An Input is a generic source mechanism; these references do not assume a URL or HTTP provider.
 */
final readonly class InputCreated
{
    /**
     * Records that the Input was created.
     *
     * @param string $inputId Input through which the source relationship is known.
     */
    public function __construct(
        public string $inputId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

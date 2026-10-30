<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Input was attached to a Stash.
 * An Input is a generic source mechanism; these references do not assume a URL or HTTP provider.
 */
final readonly class InputAttachedToStash
{
    /**
     * Records that the Input was attached to a Stash.
     *
     * @param string $inputId Input through which the source relationship is known.
     * @param string $stashId Organisational Stash affected by the change.
     */
    public function __construct(
        public string $inputId,
        public string $stashId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Acquisition made a progress checkpoint.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[TransientObservation]
final readonly class AcquisitionProgressCheckpointed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $completedComponentIds Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $acquisitionId,
        public string $checkpointId,
        public array $completedComponentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

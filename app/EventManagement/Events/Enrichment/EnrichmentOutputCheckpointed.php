<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment output was checkpointed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[TransientObservation]
final readonly class EnrichmentOutputCheckpointed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $outputReferences Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $enrichmentId,
        public string $checkpointId,
        public array $outputReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

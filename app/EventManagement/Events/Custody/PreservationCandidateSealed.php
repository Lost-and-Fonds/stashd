<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Preservation was sealed.
 * A custody receipt accepts responsibility for bytes; a later OCFL commit has its own identity and event.
 */
final readonly class PreservationCandidateSealed
{
    /**
     * Records that the Preservation was sealed.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     * @param list<string> $preconditionReferences Identified precondition references for this occurrence.
     */
    public function __construct(
        public string $candidateId,
        public string $itemId,
        public string $transactionId,
        public array $preconditionReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

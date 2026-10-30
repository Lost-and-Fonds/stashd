<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Preservation was sealed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class PreservationCandidateSealed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $preconditionReferences Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $candidateId,
        public string $itemId,
        public string $transactionId,
        public array $preconditionReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * This records the Item revision and the exact Asset versions it pins.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ItemVersionCommitted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $assetVersionReferences Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $transactionId,
        public string $itemId,
        public string $itemVersion,
        public array $assetVersionReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

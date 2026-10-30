<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * This records an authoritative Asset version commit; it does not imply an Item revision references that version.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class AssetVersionCommitted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $evidenceReferences Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $transactionId,
        public string $assetId,
        public string $assetVersion,
        public array $evidenceReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

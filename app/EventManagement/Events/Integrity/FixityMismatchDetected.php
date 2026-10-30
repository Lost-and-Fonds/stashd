<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * A mismatch records different observed and expected digests, without claiming the full cause is known.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class FixityMismatchDetected
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $checkId,
        public string $assetId,
        public string $assetVersion,
        public string $expectedDigest,
        public string $observedDigest,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

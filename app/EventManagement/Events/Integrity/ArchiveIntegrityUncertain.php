<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that available evidence cannot establish whether the affected archive bytes are intact.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
final readonly class ArchiveIntegrityUncertain
{
    /**
     * Records that available evidence cannot establish whether the affected archive bytes are intact.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $itemId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

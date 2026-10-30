<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the preservation transaction reached its own completion condition. It does not claim that a search projection is current.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
final readonly class PreservationCommitCompleted
{
    /**
     * Records that the preservation transaction reached its own completion condition. It does not claim that a search projection is current.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     */
    public function __construct(
        public string $transactionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

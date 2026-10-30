<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that conflicting evidence was isolated for review rather than guessed away or destroyed.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
final readonly class PreservationEvidenceQuarantined
{
    /**
     * Records that conflicting evidence was isolated for review rather than guessed away or destroyed.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $transactionId,
        public string $evidenceReference,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

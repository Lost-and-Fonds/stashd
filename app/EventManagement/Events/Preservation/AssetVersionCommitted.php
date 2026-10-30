<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * This records an authoritative Asset version commit; it does not imply an Item revision references that version.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class AssetVersionCommitted
{
    /**
     * This records an authoritative Asset version commit; it does not imply an Item revision references that version.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $assetVersion Exact historical OCFL Asset version, never a latest selector.
     * @param list<string> $evidenceReferences Identified evidence references for this occurrence.
     */
    public function __construct(
        public string $transactionId,
        public string $assetId,
        public string $assetVersion,
        public array $evidenceReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

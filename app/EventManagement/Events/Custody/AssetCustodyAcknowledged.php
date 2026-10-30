<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records durable acceptance of complete component bytes under Core responsibility, identified by the custody receipt.
 * A custody receipt accepts responsibility for bytes; a later OCFL commit has its own identity and event.
 */
final readonly class AssetCustodyAcknowledged
{
    /**
     * Records durable acceptance of complete component bytes under Core responsibility, identified by the custody receipt.
     *
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $componentId Stable component identity known before authoritative OCFL version allocation.
     * @param string $custodyReceiptId Durable acknowledgement under Core's custody contract.
     */
    public function __construct(
        public string $assetId,
        public string $componentId,
        public string $custodyReceiptId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Custody;

use App\EventManagement\DTO\EventMetadata;

/**
 * This records accepted custody under Core's durability contract; it does not by itself record an OCFL commit.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class AssetCustodyAcknowledged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $assetId,
        public string $assetVersion,
        public string $custodyReceiptId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}

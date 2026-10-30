<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\AssetVersionReference;
use App\EventManagement\DTO\EventMetadata;
use InvalidArgumentException;

/**
 * This records the Item revision and the exact Asset versions it pins.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ItemVersionCommitted
{
    /** @var list<AssetVersionReference> Exact historical pins validated at construction. */
    public array $assetVersionReferences;

    /**
     * This records the Item revision and the exact Asset versions it pins.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $itemVersion Exact historical OCFL Item revision.
     * @param list<AssetVersionReference> $assetVersionReferences Exact Asset identity and version pairs pinned by this Item revision.
     */
    public function __construct(
        public string $transactionId,
        public string $itemId,
        public string $itemVersion,
        array $assetVersionReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {
        // PHPDoc cannot prevent untyped callers from supplying malformed pins.
        /** @var array<array-key, mixed> $assetVersionReferences */
        if (! array_is_list($assetVersionReferences)) {
            throw new InvalidArgumentException('Item version pins must be a list.');
        }

        $pins = [];
        foreach ($assetVersionReferences as $reference) {
            if (! $reference instanceof AssetVersionReference) {
                throw new InvalidArgumentException('Item version pins must be AssetVersionReference values.');
            }

            $pins[] = $reference;
        }

        $this->assetVersionReferences = $pins;
    }
}

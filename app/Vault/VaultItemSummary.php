<?php

declare(strict_types=1);

namespace App\Vault;

use App\Fixity\PreservationHealthSummary;

final readonly class VaultItemSummary
{
    public function __construct(
        public ItemRecord $item,
        public ?string $kind,
        public int $stashCount,
        public int $broadcastCount,
        public int $preservedSizeBytes,
        public ?PreservationHealthSummary $preservation = null,
    ) {}
}

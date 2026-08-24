<?php

declare(strict_types=1);

namespace App\Vault;

final readonly class VaultItemSummary
{
    public function __construct(
        public MediaItemRecord $item,
        public ?string $kind,
        public int $stashCount,
        public int $broadcastCount,
        public int $preservedSizeBytes,
    ) {}
}

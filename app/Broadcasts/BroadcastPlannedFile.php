<?php

declare(strict_types=1);

namespace App\Broadcasts;

/** One intended generated broadcast file (plan output — no filesystem writes). */
final readonly class BroadcastPlannedFile
{
    public function __construct(
        public string $stashItemId,
        public string $itemId,
        public string $sourceAssetId,
        public string $sourcePath,
        public string $relativePath,
        public string $absolutePath,
        public string $filename,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stash_item_id' => $this->stashItemId,
            'item_id' => $this->itemId,
            'source_asset_id' => $this->sourceAssetId,
            'source_path' => $this->sourcePath,
            'relative_path' => $this->relativePath,
            'absolute_path' => $this->absolutePath,
            'filename' => $this->filename,
        ];
    }
}

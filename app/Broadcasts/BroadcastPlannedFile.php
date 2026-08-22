<?php

declare(strict_types=1);

namespace App\Broadcasts;

/** One intended generated broadcast file (plan output — no filesystem writes). */
final readonly class BroadcastPlannedFile
{
    public function __construct(
        public string $stashItemId,
        public string $mediaItemId,
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
            'media_item_id' => $this->mediaItemId,
            'source_asset_id' => $this->sourceAssetId,
            'source_path' => $this->sourcePath,
            'relative_path' => $this->relativePath,
            'absolute_path' => $this->absolutePath,
            'filename' => $this->filename,
        ];
    }
}

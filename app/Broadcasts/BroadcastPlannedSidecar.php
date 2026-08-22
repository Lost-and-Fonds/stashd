<?php

declare(strict_types=1);

namespace App\Broadcasts;

/** Intended sidecar file (generated metadata or a hardlinked Vault asset). */
final readonly class BroadcastPlannedSidecar
{
    public function __construct(
        public BroadcastSidecarType $kind,
        public string $relativePath,
        public string $absolutePath,
        public string $content,
        public ?string $stashItemId = null,
        public ?string $mediaItemId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'relative_path' => $this->relativePath,
            'absolute_path' => $this->absolutePath,
            'stash_item_id' => $this->stashItemId,
            'media_item_id' => $this->mediaItemId,
        ];
    }
}

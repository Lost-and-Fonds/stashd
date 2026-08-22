<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastPlan
{
    /**
     * @param  list<BroadcastPlannedFile>  $files
     * @param  list<BroadcastPlannedSidecar>  $sidecars
     * @param  list<string>  $skippedStashItemIds
     */
    public function __construct(
        public string $broadcastId,
        public string $broadcastRoot,
        public array $files,
        public array $sidecars = [],
        public array $skippedStashItemIds = [],
        public int $estimatedCopyBytes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'broadcast_id' => $this->broadcastId,
            'broadcast_root' => $this->broadcastRoot,
            'file_count' => count($this->files),
            'sidecar_count' => count($this->sidecars),
            'skipped_stash_item_ids' => $this->skippedStashItemIds,
            'estimated_copy_bytes' => $this->estimatedCopyBytes,
            'files' => array_map(static fn(BroadcastPlannedFile $file): array => $file->toArray(), $this->files),
            'sidecars' => array_map(static fn(BroadcastPlannedSidecar $sidecar): array => $sidecar->toArray(), $this->sidecars),
        ];
    }
}

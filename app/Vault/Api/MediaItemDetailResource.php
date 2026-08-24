<?php

declare(strict_types=1);

namespace App\Vault\Api;

use App\Broadcasts\Api\BroadcastResource;
use App\Broadcasts\BroadcastRecord;
use App\Http\Api\ApiJson;
use App\Stashes\Api\StashResource;
use App\Stashes\StashRecord;
use App\Support\DurationSeconds;
use App\Vault\AssetRecord;
use App\Vault\MediaItemRecord;

final readonly class MediaItemDetailResource
{
    /**
     * @param list<AssetRecord> $assets
     * @param list<StashRecord> $stashes
     * @param list<BroadcastRecord> $broadcasts
     */
    public function __construct(
        private MediaItemRecord $item,
        private array $assets,
        private array $stashes,
        private array $broadcasts,
        private int $preservedSizeBytes,
    ) {}

    /**
     * @param list<AssetRecord> $assets
     * @param list<StashRecord> $stashes
     * @param list<BroadcastRecord> $broadcasts
     */
    public static function fromRecord(
        MediaItemRecord $item,
        array $assets,
        array $stashes,
        array $broadcasts,
        int $preservedSizeBytes,
    ): self {
        return new self($item, $assets, $stashes, $broadcasts, $preservedSizeBytes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'item' => MediaItemResource::fromRecord($this->item)->toArray(),
            'assets' => array_map(fn(AssetRecord $asset): array => $this->asset($asset), $this->assets),
            'stashes' => array_map(static fn(StashRecord $stash): array => StashResource::fromRecord($stash)->toArray(), $this->stashes),
            'broadcasts' => array_map(static fn(BroadcastRecord $broadcast): array => BroadcastResource::fromRecord($broadcast)->toArray(), $this->broadcasts),
            'preservedSizeBytes' => $this->preservedSizeBytes,
        ]);
    }

    /** @return array<string, mixed> */
    private function asset(AssetRecord $asset): array
    {
        return [
            'id' => (string) $asset->id,
            'role' => $asset->role->value,
            'kind' => $asset->kind->value,
            'sizeBytes' => $asset->sizeBytes,
            'displayPath' => $asset->relativePath ?? ($asset->path === null ? null : basename($asset->path)),
            'mimeType' => $asset->mimeType,
            'language' => $asset->language,
            'durationSeconds' => DurationSeconds::toSeconds($asset->durationSeconds),
            'createdAt' => $asset->createdAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Vault\Api;

use App\Http\Api\ApiJson;
use App\Support\DurationSeconds;
use App\Vault\AssetRecord;
use App\Vault\AssetRegenerationGuidance;

final readonly class AssetResource
{
    public function __construct(
        private AssetRecord $asset,
        private ?AssetRegenerationGuidance $guidance = null,
    ) {}

    public static function fromRecord(AssetRecord $asset, ?AssetRegenerationGuidance $guidance = null): self
    {
        return new self($asset, $guidance);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->asset->id,
            'mediaItemId' => $this->asset->mediaItemId === null ? null : (string) $this->asset->mediaItemId,
            'broadcastId' => $this->asset->broadcastId === null ? null : (string) $this->asset->broadcastId,
            'role' => $this->asset->role->value,
            'kind' => $this->asset->kind->value,
            'state' => $this->asset->state->value,
            'derivedFromAssetId' => $this->asset->derivedFromAssetId === null ? null : (string) $this->asset->derivedFromAssetId,
            'path' => $this->asset->path,
            'relativePath' => $this->asset->relativePath,
            'mimeType' => $this->asset->mimeType,
            'container' => $this->asset->container,
            'sizeBytes' => $this->asset->sizeBytes,
            'checksum' => $this->asset->checksum,
            'durationSeconds' => DurationSeconds::toSeconds($this->asset->durationSeconds),
            'lastVerifiedAt' => $this->asset->lastVerifiedAt,
            'missingAt' => $this->asset->missingAt,
            'missingReason' => $this->asset->missingReason,
            'createdAt' => $this->asset->createdAt,
            'updatedAt' => $this->asset->updatedAt,
            'generatedBy' => $this->guidance?->generatedBy,
            'canRegenerate' => $this->guidance?->canRegenerate,
            'safeToDelete' => $this->guidance?->safeToDelete,
        ]);
    }
}

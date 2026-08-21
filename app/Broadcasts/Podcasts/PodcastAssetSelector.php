<?php

declare(strict_types=1);

namespace App\Broadcasts\Podcasts;

use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;

final readonly class PodcastAssetSelector
{
    public function __construct(
        private AssetRepository $assets,
        private PodcastMimeType $mimeTypes,
    ) {
    }

    public function audioAsset(MediaItemId $mediaItemId): ?PodcastAssetSelection
    {
        foreach ($this->candidateAssets($mediaItemId) as $asset) {
            if ($asset->kind !== AssetKind::Audio) {
                continue;
            }

            $mimeType = $this->mimeTypes->forAudioAsset($asset);

            if ($mimeType === null) {
                continue;
            }

            return $this->selection($asset, $mimeType);
        }

        return null;
    }

    public function videoAsset(MediaItemId $mediaItemId): ?PodcastAssetSelection
    {
        foreach ($this->candidateAssets($mediaItemId) as $asset) {
            if ($asset->kind !== AssetKind::Video) {
                continue;
            }

            $mimeType = $this->mimeTypes->forVideoAsset($asset);

            if ($mimeType === null) {
                continue;
            }

            return $this->selection($asset, $mimeType);
        }

        return null;
    }

    public function artworkAsset(MediaItemId $mediaItemId): ?AssetRecord
    {
        foreach ($this->assets->listForMediaItem($mediaItemId) as $asset) {
            if ($asset->role === AssetRole::SourceThumbnail && $asset->state === AssetState::Ready && $asset->path !== null && is_file($asset->path)) {
                return $asset;
            }
        }

        return null;
    }

    public function captionAsset(MediaItemId $mediaItemId): ?AssetRecord
    {
        foreach ($this->assets->listForMediaItem($mediaItemId) as $asset) {
            if ($asset->role === AssetRole::Subtitle && $asset->state === AssetState::Ready && $asset->path !== null && is_file($asset->path)) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * The ready video VaultOriginal for `$mediaItemId`, if any — used only to
     * decide whether a podcast configured for audio can fall back to
     * transcoding audio out of an existing video original. Deliberately
     * separate from {@see videoAsset()}, which is video-media-kind-specific
     * and must not be touched/reused here.
     */
    public function videoOriginalForAudioFallback(MediaItemId $mediaItemId): ?AssetRecord
    {
        foreach ($this->assets->listForMediaItem($mediaItemId) as $asset) {
            if (
                $asset->role === AssetRole::VaultOriginal
                && $asset->kind === AssetKind::Video
                && $asset->state === AssetState::Ready
                && $asset->path !== null
                && is_file($asset->path)
            ) {
                return $asset;
            }
        }

        return null;
    }

    /** @return list<AssetRecord> */
    private function candidateAssets(MediaItemId $mediaItemId): array
    {
        $assets = array_filter(
            $this->assets->listForMediaItem($mediaItemId),
            static fn (AssetRecord $asset): bool => $asset->state === AssetState::Ready
                && $asset->path !== null
                && is_file($asset->path)
                && in_array($asset->role, [AssetRole::VaultOriginal, AssetRole::PodcastAudio], true),
        );

        usort(
            $assets,
            static fn (AssetRecord $a, AssetRecord $b): int => self::assetPriority($a) <=> self::assetPriority($b),
        );

        return array_values($assets);
    }

    private static function assetPriority(AssetRecord $asset): int
    {
        return match ($asset->role) {
            AssetRole::PodcastAudio => 0,
            AssetRole::VaultOriginal => 1,
            default => 9,
        };
    }

    private function selection(AssetRecord $asset, string $mimeType): PodcastAssetSelection
    {
        $length = $asset->sizeBytes;

        if ($length === null || $length < 0) {
            $size = $asset->path === null ? false : filesize($asset->path);
            $length = is_int($size) ? $size : 0;
        }

        return new PodcastAssetSelection(
            asset: $asset,
            mimeType: $mimeType,
            extension: $this->mimeTypes->extensionForAsset($asset, $mimeType),
            length: $length,
        );
    }
}

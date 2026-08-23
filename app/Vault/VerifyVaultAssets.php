<?php

declare(strict_types=1);

namespace App\Vault;

use App\System\State\StateTransitionService;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use Closure;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Support\Filesystem\is_file;

final readonly class VerifyVaultAssets
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private AssetRepository $assets,
        private MediaItemRepository $mediaItems,
        private StorageLocationRepository $storageLocations,
        private StateTransitionService $transitions,
    ) {}

    /** @param null|Closure(int, int): void $onProgress */
    public function verifyAll(?Closure $onProgress = null): VaultVerifyResult
    {
        $vault = $this->storageLocations->findByKey(StorageLocationKey::Vault);

        if ($vault !== null && in_array($vault->state, [StorageLocationState::Unavailable, StorageLocationState::Missing], true)) {
            return new VaultVerifyResult(
                checked: 0,
                missing: 0,
                restored: 0,
                checksumMismatch: 0,
                storageUnavailable: true,
            );
        }

        $checked = 0;
        $missing = 0;
        $restored = 0;
        $checksumMismatch = 0;

        $total = $this->assets->countReadyVaultAssets();
        $onProgress?->__invoke(0, $total);
        $afterId = null;

        while (true) {
            $assets = $this->assets->listReadyVaultAssetsPage($afterId, self::PAGE_SIZE);

            if ($assets === []) {
                break;
            }

            foreach ($assets as $asset) {
                $result = $this->verifyAssetRecord(
                    $asset,
                    $onProgress === null ? null : function () use ($onProgress, $checked, $total): void {
                        $onProgress($checked, $total);
                    },
                );
                $checked++;
                $onProgress?->__invoke($checked, $total);

                match ($result) {
                    VerifyAssetOutcome::Missing => $missing++,
                    VerifyAssetOutcome::Restored => $restored++,
                    VerifyAssetOutcome::ChecksumMismatch => $checksumMismatch++,
                    default => null,
                };
            }

            $afterId = (string) $assets[array_key_last($assets)]->id;
        }

        return new VaultVerifyResult(
            checked: $checked,
            missing: $missing,
            restored: $restored,
            checksumMismatch: $checksumMismatch,
            storageUnavailable: false,
        );
    }

    public function verifyAsset(AssetId $assetId): VerifyAssetOutcome
    {
        $asset = $this->assets->find($assetId);

        if ($asset === null) {
            return VerifyAssetOutcome::NotFound;
        }

        $vault = $this->storageLocations->findByKey(StorageLocationKey::Vault);

        if ($vault !== null && in_array($vault->state, [StorageLocationState::Unavailable, StorageLocationState::Missing], true)) {
            return VerifyAssetOutcome::StorageUnavailable;
        }

        return $this->verifyAssetRecord($asset);
    }

    private function verifyAssetRecord(AssetRecord $asset, ?Closure $onChecksumChunk = null): VerifyAssetOutcome
    {
        if ($asset->path === null) {
            return VerifyAssetOutcome::Skipped;
        }

        if (! is_file($asset->path) || ! is_readable($asset->path)) {
            return $this->markMissing($asset);
        }

        if ($asset->checksum !== null && ! VaultChecksum::verifyFile($asset->path, $asset->checksum, $onChecksumChunk)) {
            return $this->markChecksumMismatch($asset);
        }

        $asset->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $asset->missingAt = null;
        $asset->missingReason = null;

        if ($asset->state === AssetState::Missing || $asset->state === AssetState::Stale) {
            $this->transitions->transitionAsset($asset, AssetState::Ready);
            $this->syncMediaItemAfterAssetRestore($asset);
            $this->assets->save($asset);

            return VerifyAssetOutcome::Restored;
        }

        $this->assets->save($asset);

        return VerifyAssetOutcome::Ok;
    }

    private function markMissing(AssetRecord $asset): VerifyAssetOutcome
    {
        if ($asset->state !== AssetState::Missing) {
            $this->transitions->transitionAsset($asset, AssetState::Missing);
        }

        $asset->missingAt = DateTime::now(Timezone::UTC);
        $asset->missingReason = 'vault_file_missing';
        $this->assets->save($asset);
        $this->syncMediaItemAfterAssetMissing($asset);

        return VerifyAssetOutcome::Missing;
    }

    private function markChecksumMismatch(AssetRecord $asset): VerifyAssetOutcome
    {
        if ($asset->state !== AssetState::Stale) {
            $this->transitions->transitionAsset($asset, AssetState::Stale);
        }

        $asset->missingAt = DateTime::now(Timezone::UTC);
        $asset->missingReason = 'checksum_mismatch';
        $this->assets->save($asset);

        return VerifyAssetOutcome::ChecksumMismatch;
    }

    private function syncMediaItemAfterAssetMissing(AssetRecord $asset): void
    {
        if ($asset->mediaItemId === null || $asset->role !== AssetRole::VaultOriginal) {
            return;
        }

        $mediaItem = $this->mediaItems->find($asset->mediaItemId);

        if ($mediaItem === null) {
            return;
        }

        if ($mediaItem->state === MediaItemState::Ready) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Missing);
        } elseif ($mediaItem->state === MediaItemState::Failed) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Missing);
        }
    }

    private function syncMediaItemAfterAssetRestore(AssetRecord $asset): void
    {
        if ($asset->mediaItemId === null || $asset->role !== AssetRole::VaultOriginal) {
            return;
        }

        $mediaItem = $this->mediaItems->find($asset->mediaItemId);

        if ($mediaItem === null) {
            return;
        }

        if ($mediaItem->state === MediaItemState::Missing) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Ready);
        }
    }
}

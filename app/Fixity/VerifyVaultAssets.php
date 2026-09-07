<?php

declare(strict_types=1);

namespace App\Fixity;

use App\System\State\StateTransitionService;
use App\System\Storage\VaultStorageAvailability;
use App\Vault\AssetId;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use Closure;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Support\Filesystem;

final readonly class VerifyVaultAssets
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private AssetRepository $assets,
        private ItemRepository $items,
        private StateTransitionService $transitions,
        private PreservationEventRepository $events,
        private VaultStorageAvailability $storage,
    ) {}

    /** @param null|Closure(int, int): void $onProgress */
    public function verifyAll(?Closure $onProgress = null, ?string $jobId = null): VaultVerifyResult
    {
        if ($this->isVaultStorageUnavailable()) {
            return new VaultVerifyResult(
                checked: 0,
                missing: 0,
                restored: 0,
                checksumMismatch: 0,
                unverified: 0,
                storageUnavailable: true,
            );
        }

        $checked = 0;
        $missing = 0;
        $restored = 0;
        $checksumMismatch = 0;
        $unverified = 0;

        $total = $this->assets->countVerifiableVaultAssets();
        $onProgress?->__invoke(0, $total);
        $afterId = null;

        while (true) {
            $assets = $this->assets->listVerifiableVaultAssetsPage($afterId, self::PAGE_SIZE);

            if ($assets === []) {
                break;
            }

            foreach ($assets as $asset) {
                if ($this->isVaultStorageUnavailable()) {
                    return new VaultVerifyResult(
                        checked: $checked,
                        missing: $missing,
                        restored: $restored,
                        checksumMismatch: $checksumMismatch,
                        unverified: $unverified,
                        storageUnavailable: true,
                    );
                }

                $result = $this->verifyAssetRecord(
                    $asset,
                    $onProgress === null ? null : function () use ($onProgress, &$checked, $total): void {
                        $onProgress($checked, $total);
                    },
                    $jobId,
                );
                $checked++;
                $onProgress?->__invoke($checked, $total);

                if ($result->outcome === VerifyAssetOutcome::StorageUnavailable) {
                    return new VaultVerifyResult(
                        checked: $checked - 1,
                        missing: $missing,
                        restored: $restored,
                        checksumMismatch: $checksumMismatch,
                        unverified: $unverified,
                        storageUnavailable: true,
                    );
                }

                match ($result->outcome) {
                    VerifyAssetOutcome::Missing => $missing++,
                    VerifyAssetOutcome::ChecksumMismatch => $checksumMismatch++,
                    VerifyAssetOutcome::Unverified => $unverified++,
                    default => null,
                };

                if ($result->restored) {
                    $restored++;
                }
            }

            $afterId = (string) $assets[array_key_last($assets)]->id;
        }

        return new VaultVerifyResult(
            checked: $checked,
            missing: $missing,
            restored: $restored,
            checksumMismatch: $checksumMismatch,
            unverified: $unverified,
            storageUnavailable: false,
        );
    }

    public function verifyAsset(AssetId $assetId, ?string $jobId = null): FixityVerificationResult
    {
        $asset = $this->assets->find($assetId);

        if ($asset === null) {
            return new FixityVerificationResult(VerifyAssetOutcome::NotFound);
        }

        if ($this->isVaultStorageUnavailable()) {
            return new FixityVerificationResult(
                outcome: VerifyAssetOutcome::StorageUnavailable,
                expectedChecksum: $asset->checksum,
            );
        }

        return $this->verifyAssetRecord($asset, jobId: $jobId);
    }

    private function verifyAssetRecord(AssetRecord $asset, ?Closure $onChecksumChunk = null, ?string $jobId = null): FixityVerificationResult
    {
        if ($this->isVaultStorageUnavailable()) {
            return new FixityVerificationResult(
                outcome: VerifyAssetOutcome::StorageUnavailable,
                expectedChecksum: $asset->checksum,
            );
        }

        if ($asset->path === null) {
            return new FixityVerificationResult(
                outcome: VerifyAssetOutcome::Skipped,
                expectedChecksum: $asset->checksum,
            );
        }

        if (! Filesystem\is_file($asset->path) || ! Filesystem\is_readable($asset->path)) {
            return $this->markMissing($asset, $jobId);
        }

        $comparison = VaultChecksum::verifyFile($asset->path, $asset->checksum, $onChecksumChunk);

        if ($comparison->outcome === ChecksumComparisonOutcome::Unavailable) {
            return new FixityVerificationResult(
                outcome: VerifyAssetOutcome::StorageUnavailable,
                expectedChecksum: $comparison->expectedChecksum,
            );
        }

        if ($comparison->outcome === ChecksumComparisonOutcome::Mismatch) {
            return $this->markChecksumMismatch($asset, $comparison, $jobId);
        }

        if ($comparison->outcome === ChecksumComparisonOutcome::Unverified) {
            $restored = in_array($asset->state, [AssetState::Missing, AssetState::Stale], true);
            $asset->lastVerifiedAt = null;
            $asset->missingAt = null;
            $asset->missingReason = null;

            if ($restored) {
                $this->transitions->transitionAsset($asset, AssetState::Ready);
                $this->syncItemAfterAssetRestore($asset);
            }

            $this->assets->save($asset);
            $this->events->create(
                assetId: AssetId::fromPrimaryKey($asset->id),
                eventType: PreservationEventType::FixityCheck,
                outcome: PreservationOutcome::Unverified,
                observedChecksum: $comparison->observedChecksum,
                jobId: $jobId,
                detail: ['reason' => 'expected_checksum_absent'],
            );

            return new FixityVerificationResult(
                outcome: VerifyAssetOutcome::Unverified,
                observedChecksum: $comparison->observedChecksum,
                restored: $restored,
            );
        }

        $restored = in_array($asset->state, [AssetState::Missing, AssetState::Stale], true);
        $asset->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $asset->missingAt = null;
        $asset->missingReason = null;

        if ($restored) {
            $this->transitions->transitionAsset($asset, AssetState::Ready);
            $this->syncItemAfterAssetRestore($asset);
            $this->assets->save($asset);
        } else {
            $this->assets->save($asset);
        }

        $this->events->create(
            assetId: AssetId::fromPrimaryKey($asset->id),
            eventType: PreservationEventType::FixityCheck,
            outcome: PreservationOutcome::Success,
            expectedChecksum: $comparison->expectedChecksum,
            observedChecksum: $comparison->observedChecksum,
            jobId: $jobId,
        );

        return new FixityVerificationResult(
            outcome: $restored ? VerifyAssetOutcome::Restored : VerifyAssetOutcome::Ok,
            expectedChecksum: $comparison->expectedChecksum,
            observedChecksum: $comparison->observedChecksum,
            restored: $restored,
        );
    }

    private function markMissing(AssetRecord $asset, ?string $jobId): FixityVerificationResult
    {
        if ($asset->state !== AssetState::Missing) {
            $this->transitions->transitionAsset($asset, AssetState::Missing);
        }

        $asset->missingAt = DateTime::now(Timezone::UTC);
        $asset->missingReason = 'vault_file_missing';
        $this->assets->save($asset);
        $this->syncItemAfterAssetMissing($asset);
        $this->events->create(
            assetId: AssetId::fromPrimaryKey($asset->id),
            eventType: PreservationEventType::FixityCheck,
            outcome: PreservationOutcome::Missing,
            expectedChecksum: $asset->checksum,
            jobId: $jobId,
        );

        return new FixityVerificationResult(
            outcome: VerifyAssetOutcome::Missing,
            expectedChecksum: $asset->checksum,
        );
    }

    private function markChecksumMismatch(AssetRecord $asset, ChecksumComparison $comparison, ?string $jobId): FixityVerificationResult
    {
        if ($asset->state !== AssetState::Stale) {
            $this->transitions->transitionAsset($asset, AssetState::Stale);
        }

        $asset->missingAt = null;
        $asset->missingReason = null;
        $this->assets->save($asset);
        $this->events->create(
            assetId: AssetId::fromPrimaryKey($asset->id),
            eventType: PreservationEventType::FixityCheck,
            outcome: PreservationOutcome::Mismatch,
            expectedChecksum: $comparison->expectedChecksum,
            observedChecksum: $comparison->observedChecksum,
            jobId: $jobId,
        );

        return new FixityVerificationResult(
            outcome: VerifyAssetOutcome::ChecksumMismatch,
            expectedChecksum: $comparison->expectedChecksum,
            observedChecksum: $comparison->observedChecksum,
        );
    }

    /** @phpstan-impure */
    private function isVaultStorageUnavailable(): bool
    {
        return $this->storage->isUnavailable();
    }

    private function syncItemAfterAssetMissing(AssetRecord $asset): void
    {
        if ($asset->itemId === null || $asset->role !== AssetRole::VaultOriginal) {
            return;
        }

        $item = $this->items->find($asset->itemId);

        if ($item === null) {
            return;
        }

        if ($item->state === ItemState::Ready || $item->state === ItemState::Failed) {
            $this->transitions->transitionItem($item, ItemState::Missing);
        }
    }

    private function syncItemAfterAssetRestore(AssetRecord $asset): void
    {
        if ($asset->itemId === null || $asset->role !== AssetRole::VaultOriginal) {
            return;
        }

        $item = $this->items->find($asset->itemId);

        if ($item?->state === ItemState::Missing) {
            $this->transitions->transitionItem($item, ItemState::Ready);
        }
    }
}

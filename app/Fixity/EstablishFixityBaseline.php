<?php

declare(strict_types=1);

namespace App\Fixity;

use App\System\Storage\VaultStorageAvailability;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetState;
use Tempest\Support\Filesystem;

final readonly class EstablishFixityBaseline
{
    public function __construct(
        private AssetRepository $assets,
        private PreservationEventRepository $events,
        private VaultStorageAvailability $storage,
    ) {}

    public function establish(AssetId $assetId): EstablishFixityBaselineResult
    {
        $asset = $this->assets->find($assetId);

        if ($asset === null) {
            return new EstablishFixityBaselineResult(EstablishFixityBaselineOutcome::NotFound);
        }

        if ($asset->checksum !== null && $asset->checksum !== '') {
            return new EstablishFixityBaselineResult(
                EstablishFixityBaselineOutcome::AlreadyHasBaseline,
                expectedChecksum: $asset->checksum,
            );
        }

        if (! $asset->participatesInPreservationHealth() || $asset->state !== AssetState::Ready || $asset->path === null) {
            return new EstablishFixityBaselineResult(EstablishFixityBaselineOutcome::NotEligible);
        }

        if ($this->storage->isUnavailable()) {
            return new EstablishFixityBaselineResult(EstablishFixityBaselineOutcome::StorageUnavailable);
        }

        if (! Filesystem\is_file($asset->path) || ! Filesystem\is_readable($asset->path)) {
            return new EstablishFixityBaselineResult(EstablishFixityBaselineOutcome::FileUnavailable);
        }

        $checksum = VaultChecksum::computeFile($asset->path);

        if ($checksum === null) {
            return new EstablishFixityBaselineResult(EstablishFixityBaselineOutcome::ChecksumFailed);
        }

        $asset->checksum = $checksum;
        $asset->lastVerifiedAt = null;
        $this->assets->save($asset);
        $this->events->create(
            assetId: $assetId,
            eventType: PreservationEventType::FixityGenerated,
            outcome: PreservationOutcome::Success,
            expectedChecksum: $checksum,
            observedChecksum: $checksum,
            detail: [
                'source' => 'retrospective_baseline',
                'reason' => 'legacy_asset_without_expected_checksum',
            ],
        );

        return new EstablishFixityBaselineResult(
            EstablishFixityBaselineOutcome::Established,
            expectedChecksum: $checksum,
            observedChecksum: $checksum,
        );
    }
}

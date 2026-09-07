<?php

declare(strict_types=1);

namespace App\Fixity;

use App\System\Storage\VaultStorageAvailability;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;

final readonly class PreservationHealthService
{
    public function __construct(
        private AssetRepository $assets,
        private FixityStatusResolver $fixity,
        private PreservationHealthResolver $health,
        private VaultStorageAvailability $storage,
    ) {}

    public function vaultSummary(): VaultPreservationSummary
    {
        $assets = $this->assets->listPreservedForHealth();
        $statuses = $this->fixity->forAssets($assets);
        $fixityCounts = array_fill_keys(array_map(static fn(FixityStatus $status): string => $status->value, FixityStatus::cases()), 0);
        $healthCounts = array_fill_keys(array_map(static fn(PreservationHealth $health): string => $health->value, PreservationHealth::cases()), 0);
        $oldest = null;

        foreach ($assets as $asset) {
            $status = $statuses[(string) $asset->id] ?? FixityStatus::Unverified;
            $fixityCounts[$status->value]++;
            $healthCounts[PreservationHealthResolver::healthForStatus($status)->value]++;

            if ($asset->lastVerifiedAt !== null && ($oldest === null || $asset->lastVerifiedAt->before($oldest))) {
                $oldest = $asset->lastVerifiedAt;
            }
        }

        $items = $this->health->forItems($assets, $statuses);
        $criticalItems = 0;
        $attentionItems = 0;

        foreach ($items as $summary) {
            $criticalItems += $summary->health === PreservationHealth::Critical ? 1 : 0;
            $attentionItems += $summary->health === PreservationHealth::Attention ? 1 : 0;
        }

        $storageUnavailable = $this->storage->isUnavailable();

        return new VaultPreservationSummary(
            health: $storageUnavailable ? PreservationHealth::Unknown : $this->rollup($fixityCounts),
            fixityCounts: $fixityCounts,
            healthCounts: $healthCounts,
            totalPreservedAssets: count($assets),
            verifiableAssets: count(array_filter($assets, static fn(AssetRecord $asset): bool => $asset->checksum !== null && $asset->checksum !== '')),
            oldestSuccessfulVerificationAt: $oldest,
            criticalItems: $criticalItems,
            attentionItems: $attentionItems,
            storageUnavailable: $storageUnavailable,
        );
    }

    /** @param array<string, int> $counts */
    private function rollup(array $counts): PreservationHealth
    {
        if (($counts[FixityStatus::Mismatch->value] ?? 0) > 0 || ($counts[FixityStatus::Missing->value] ?? 0) > 0) {
            return PreservationHealth::Critical;
        }

        if (($counts[FixityStatus::Verifying->value] ?? 0) > 0) {
            return PreservationHealth::Checking;
        }

        if (($counts[FixityStatus::Due->value] ?? 0) > 0 || ($counts[FixityStatus::Unverified->value] ?? 0) > 0) {
            return PreservationHealth::Attention;
        }

        return PreservationHealth::Healthy;
    }
}

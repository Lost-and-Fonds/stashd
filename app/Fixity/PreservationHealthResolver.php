<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Vault\AssetRecord;
use App\Vault\AssetRole;

final readonly class PreservationHealthResolver
{
    public function __construct(private FixityStatusResolver $fixity) {}

    public function forAsset(AssetRecord $asset, ?FixityStatus $status = null): PreservationHealth
    {
        return self::healthForStatus($status ?? $this->fixity->forAsset($asset));
    }

    /** @param list<AssetRecord> $assets
     * @param array<string, FixityStatus>|null $statuses
     * @return array<string, PreservationHealthSummary>
     */
    public function forItems(array $assets, ?array $statuses = null): array
    {
        $assets = self::participatingAssets($assets);
        $statuses ??= $this->fixity->forAssets($assets);
        $byItem = [];

        foreach ($assets as $asset) {
            if ($asset->itemId === null) {
                continue;
            }

            $byItem[(string) $asset->itemId][] = $asset;
        }

        $summaries = [];

        foreach ($byItem as $itemId => $itemAssets) {
            $summaries[$itemId] = $this->forItem($itemAssets, $statuses);
        }

        return $summaries;
    }

    /** @param list<AssetRecord> $assets
     * @param array<string, FixityStatus>|null $statuses
     */
    public function forItem(array $assets, ?array $statuses = null): PreservationHealthSummary
    {
        $assets = self::participatingAssets($assets);
        $statuses ??= $this->fixity->forAssets($assets);
        $fixityCounts = self::zeroCounts(FixityStatus::cases());
        $healthCounts = self::zeroCounts(PreservationHealth::cases());
        $canonicalStatuses = [];

        foreach ($assets as $asset) {
            if (! in_array($asset->role, AssetRole::preserved(), true)) {
                continue;
            }

            $status = $statuses[(string) $asset->id] ?? FixityStatus::Unverified;
            $health = self::healthForStatus($status);
            $fixityCounts[$status->value]++;
            $healthCounts[$health->value]++;

            if ($asset->role === AssetRole::VaultOriginal) {
                $canonicalStatuses[] = $status;
            }
        }

        $health = self::rollup($canonicalStatuses, $healthCounts);

        return new PreservationHealthSummary($health, $fixityCounts, $healthCounts);
    }

    /** @param list<AssetRecord> $assets
     * @return list<AssetRecord>
     */
    private static function participatingAssets(array $assets): array
    {
        return array_values(array_filter(
            $assets,
            static fn(AssetRecord $asset): bool => $asset->participatesInPreservationHealth(),
        ));
    }

    public static function healthForStatus(FixityStatus $status): PreservationHealth
    {
        return match ($status) {
            FixityStatus::Verified => PreservationHealth::Healthy,
            FixityStatus::Verifying => PreservationHealth::Checking,
            FixityStatus::Mismatch, FixityStatus::Missing => PreservationHealth::Critical,
            FixityStatus::Due, FixityStatus::Unverified => PreservationHealth::Attention,
        };
    }

    /** @param list<FixityStatus> $canonicalStatuses
     * @param array<string, int> $healthCounts
     */
    private static function rollup(array $canonicalStatuses, array $healthCounts): PreservationHealth
    {
        if (in_array(FixityStatus::Mismatch, $canonicalStatuses, true) || in_array(FixityStatus::Missing, $canonicalStatuses, true)) {
            return PreservationHealth::Critical;
        }

        if ($healthCounts[PreservationHealth::Critical->value] > 0) {
            return PreservationHealth::Attention;
        }

        if ($healthCounts[PreservationHealth::Checking->value] > 0) {
            return PreservationHealth::Checking;
        }

        if ($healthCounts[PreservationHealth::Attention->value] > 0 || $canonicalStatuses === []) {
            return PreservationHealth::Attention;
        }

        return PreservationHealth::Healthy;
    }

    /** @param list<FixityStatus|PreservationHealth> $cases
     * @return array<string, int>
     */
    private static function zeroCounts(array $cases): array
    {
        $counts = [];

        foreach ($cases as $case) {
            $counts[$case->value] = 0;
        }

        return $counts;
    }
}

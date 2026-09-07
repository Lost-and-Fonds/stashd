<?php

declare(strict_types=1);

namespace App\Vault;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Support\DurationSeconds;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class AssetRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        ItemId $itemId,
        AssetRole $role,
        AssetKind $kind,
        AssetState $state = AssetState::Pending,
        ?string $path = null,
        ?string $relativePath = null,
        ?string $mimeType = null,
        ?string $container = null,
        ?int $sizeBytes = null,
        ?string $checksum = null,
        ?int $durationSeconds = null,
        ?string $language = null,
        ?string $derivationKey = null,
    ): AssetRecord {
        $id = $this->ids->generate('asset')->toString();
        $record = new AssetRecord(
            role: $role,
            kind: $kind,
            state: $state,
            itemId: $itemId,
            path: $path,
            relativePath: $relativePath,
            mimeType: $mimeType,
            container: $container,
            sizeBytes: $sizeBytes,
            checksum: $checksum,
            durationSeconds: DurationSeconds::toDuration($durationSeconds),
            language: $language,
            derivationKey: $derivationKey,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(AssetRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(AssetId $id): ?AssetRecord
    {
        return AssetRecord::findById($id->toPrimaryKey());
    }

    public function save(AssetRecord $record): AssetRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    public function findByItemAndRole(ItemId $itemId, AssetRole $role): ?AssetRecord
    {
        /** @var AssetRecord|null $asset */
        $asset = AssetRecord::select()
            ->where('itemId', $itemId->toString())
            ->where('role', $role)
            ->first();

        return $asset;
    }

    public function findByBroadcastItemAndRole(BroadcastItemId $broadcastItemId, AssetRole $role): ?AssetRecord
    {
        $asset = AssetRecord::select()
            ->where('broadcastItemId', $broadcastItemId->toString())
            ->where('role', $role)
            ->first();

        return $asset instanceof AssetRecord ? $asset : null;
    }

    public function findDerived(ItemId $itemId, AssetKind $kind, string $derivationKey): ?AssetRecord
    {
        $asset = AssetRecord::select()
            ->where('itemId', $itemId->toString())
            ->where('role', AssetRole::Derived)
            ->where('kind', $kind)
            ->where('derivationKey', $derivationKey)
            ->first();

        return $asset instanceof AssetRecord ? $asset : null;
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, AssetRecord> keyed by item id
     */
    public function readyVaultOriginalsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $originals = [];

        foreach (AssetRecord::select()
            ->whereIn('itemId', $itemIds)
            ->where('role', AssetRole::VaultOriginal)
            ->where('state', AssetState::Ready)
            ->whereNotNull('path')
            ->all() as $asset) {
            if (! $asset instanceof AssetRecord) {
                continue;
            }

            $originals[(string) $asset->itemId] ??= $asset;
        }

        return $originals;
    }

    /** @return list<AssetRecord> */
    public function listForItem(ItemId $itemId): array
    {
        /** @var list<AssetRecord> $assets */
        $assets = AssetRecord::select()
            ->where('itemId', $itemId->toString())
            ->all();

        return $assets;
    }

    /** @param list<string> $itemIds
     * @return list<AssetRecord>
     */
    public function listPreservedForItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return array_values(array_filter(
            AssetRecord::select()
                ->whereIn('itemId', $itemIds)
                ->whereIn('role', AssetRole::preserved())
                ->whereIn('state', [AssetState::Ready, AssetState::Stale, AssetState::Missing])
                ->whereNull('broadcastId')
                ->whereNull('broadcastItemId')
                ->all(),
            static fn(mixed $asset): bool => $asset instanceof AssetRecord && $asset->participatesInPreservationHealth(),
        ));
    }

    /** @return list<AssetRecord> */
    public function listPreservedForHealth(): array
    {
        return array_values(array_filter(
            AssetRecord::select()
                ->whereIn('role', AssetRole::preserved())
                ->whereIn('state', [AssetState::Ready, AssetState::Stale, AssetState::Missing])
                ->whereNull('broadcastId')
                ->whereNull('broadcastItemId')
                ->all(),
            static fn(mixed $asset): bool => $asset instanceof AssetRecord && $asset->participatesInPreservationHealth(),
        ));
    }

    /** @return list<AssetRecord> */
    public function listReadyPreservedForItem(ItemId $itemId): array
    {
        return array_values(array_filter(
            $this->listForItem($itemId),
            static fn(AssetRecord $asset): bool => $asset->state === AssetState::Ready
                && in_array($asset->role, AssetRole::preserved(), true)
                && $asset->broadcastId === null
                && $asset->broadcastItemId === null,
        ));
    }

    public function preservedSizeBytesForItem(ItemId $itemId): int
    {
        return array_sum(array_map(
            static fn(AssetRecord $asset): int => $asset->sizeBytes ?? 0,
            $this->listReadyPreservedForItem($itemId),
        ));
    }

    /** @return list<AssetRecord> */
    public function listByBroadcastAndRole(BroadcastId $broadcastId, AssetRole $role): array
    {
        /** @var list<AssetRecord> $assets */
        $assets = AssetRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('role', $role)
            ->all();

        return $assets;
    }

    public function countVerifiableVaultAssets(): int
    {
        return AssetRecord::count()
            ->whereIn('state', [AssetState::Ready, AssetState::Stale, AssetState::Missing])
            ->whereNotNull('path')
            ->execute();
    }

    /** @return list<AssetRecord> */
    public function listVerifiableVaultAssetsPage(?string $afterId, int $limit): array
    {
        $query = AssetRecord::select()
            ->whereIn('state', [AssetState::Ready, AssetState::Stale, AssetState::Missing])
            ->whereNotNull('path')
            ->orderBy('id', Direction::ASC)
            ->limit($limit);

        if ($afterId !== null) {
            $query->where('id', $afterId, '>');
        }

        $assets = [];

        foreach ($query->all() as $asset) {
            if ($asset instanceof AssetRecord) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * Total on-disk size across every asset for each of the given media
     * items, in one query — avoids an N+1 per stash item on the items list.
     *
     * @param  list<string>  $itemIds
     * @return array<string, int> keyed by item id
     */
    public function totalSizeBytesByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $totals = [];

        foreach (AssetRecord::select()->whereIn('itemId', $itemIds)->all() as $asset) {
            if (! $asset instanceof AssetRecord) {
                continue;
            }

            $key = (string) $asset->itemId;
            $totals[$key] = ($totals[$key] ?? 0) + ($asset->sizeBytes ?? 0);
        }

        return $totals;
    }
}

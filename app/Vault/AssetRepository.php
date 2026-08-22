<?php

declare(strict_types=1);

namespace App\Vault;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Support\DurationSeconds;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class AssetRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        MediaItemId $mediaItemId,
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
            mediaItemId: $mediaItemId,
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

    public function findByMediaItemAndRole(MediaItemId $mediaItemId, AssetRole $role): ?AssetRecord
    {
        return AssetRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->where('role', $role)
            ->first();
    }

    public function findByBroadcastItemAndRole(BroadcastItemId $broadcastItemId, AssetRole $role): ?AssetRecord
    {
        $asset = AssetRecord::select()
            ->where('broadcastItemId', $broadcastItemId->toString())
            ->where('role', $role)
            ->first();

        return $asset instanceof AssetRecord ? $asset : null;
    }

    public function findDerived(MediaItemId $mediaItemId, AssetKind $kind, string $derivationKey): ?AssetRecord
    {
        $asset = AssetRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->where('role', AssetRole::Derived)
            ->where('kind', $kind)
            ->where('derivationKey', $derivationKey)
            ->first();

        return $asset instanceof AssetRecord ? $asset : null;
    }

    /**
     * @param  list<string>  $mediaItemIds
     * @return array<string, AssetRecord> keyed by media item id
     */
    public function readyVaultOriginalsByMediaItem(array $mediaItemIds): array
    {
        if ($mediaItemIds === []) {
            return [];
        }

        $originals = [];

        foreach (AssetRecord::select()
            ->whereIn('mediaItemId', $mediaItemIds)
            ->where('role', AssetRole::VaultOriginal)
            ->where('state', AssetState::Ready)
            ->whereNotNull('path')
            ->all() as $asset) {
            if (! $asset instanceof AssetRecord) {
                continue;
            }

            $originals[(string) $asset->mediaItemId] ??= $asset;
        }

        return $originals;
    }

    /** @return list<AssetRecord> */
    public function listForMediaItem(MediaItemId $mediaItemId): array
    {
        return AssetRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->all();
    }

    /** @return list<AssetRecord> */
    public function listByBroadcastAndRole(BroadcastId $broadcastId, AssetRole $role): array
    {
        return AssetRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('role', $role)
            ->all();
    }

    public function countReadyVaultAssets(): int
    {
        return AssetRecord::count()
            ->where('state', AssetState::Ready)
            ->whereNotNull('path')
            ->execute();
    }

    /** @return list<AssetRecord> */
    public function listReadyVaultAssetsPage(?string $afterId, int $limit): array
    {
        $query = AssetRecord::select()
            ->where('state', AssetState::Ready)
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
     * @param  list<string>  $mediaItemIds
     * @return array<string, int> keyed by media item id
     */
    public function totalSizeBytesByMediaItem(array $mediaItemIds): array
    {
        if ($mediaItemIds === []) {
            return [];
        }

        $totals = [];

        foreach (AssetRecord::select()->whereIn('mediaItemId', $mediaItemIds)->all() as $asset) {
            if (! $asset instanceof AssetRecord) {
                continue;
            }

            $key = (string) $asset->mediaItemId;
            $totals[$key] = ($totals[$key] ?? 0) + ($asset->sizeBytes ?? 0);
        }

        return $totals;
    }
}

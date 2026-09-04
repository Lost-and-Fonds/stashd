<?php

declare(strict_types=1);

namespace App\Vault;

use App\Providers\StashdUri;
use App\Support\DurationSeconds;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Database;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class MediaItemRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
        private Database $database,
    ) {}

    public function create(
        string $providerKey,
        string $providerItemId,
        StashdUri|string $canonicalUri,
        string $title,
        MediaItemState $state = MediaItemState::Discovered,
        ?string $description = null,
        ?int $durationSeconds = null,
        ?DateTime $publishedAt = null,
        StashdUri|string|null $thumbnailUri = null,
        ?string $contentType = null,
        ?int $sizeBytes = null,
        bool $sizeEstimated = false,
        UpstreamState $upstreamState = UpstreamState::Available,
    ): MediaItemRecord {
        $id = $this->ids->generate('media')->toString();
        $record = new MediaItemRecord(
            providerKey: $providerKey,
            providerItemId: $providerItemId,
            canonicalUri: $canonicalUri instanceof StashdUri ? $canonicalUri->toString() : $canonicalUri,
            title: $title,
            state: $state,
            upstreamState: $upstreamState,
            description: $description,
            durationSeconds: DurationSeconds::toDuration($durationSeconds),
            publishedAt: $publishedAt,
            thumbnailUri: $thumbnailUri instanceof StashdUri ? $thumbnailUri->toString() : $thumbnailUri,
            contentType: $contentType,
            sizeBytes: $sizeBytes,
            sizeEstimated: $sizeEstimated,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(MediaItemRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(MediaItemId $id): ?MediaItemRecord
    {
        return MediaItemRecord::findById($id->toPrimaryKey());
    }

    public function findByProviderIdentity(string $providerKey, string $providerItemId): ?MediaItemRecord
    {
        /** @var MediaItemRecord|null $item */
        $item = MediaItemRecord::select()
            ->where('providerKey', $providerKey)
            ->where('providerItemId', $providerItemId)
            ->first();

        return $item;
    }

    public function save(MediaItemRecord $record): MediaItemRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /** @return list<MediaItemRecord> */
    public function list(?int $limit = null, ?int $offset = null): array
    {
        $query = MediaItemRecord::select()
            ->orderBy('createdAt', Direction::DESC);

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        /** @var list<MediaItemRecord> $items */
        $items = $query->all();

        return $items;
    }

    public function count(): int
    {
        return MediaItemRecord::count()->execute();
    }

    /** @return list<VaultItemSummary> */
    public function listVaultSummary(int $limit, int $offset, ?string $search = null, ?string $kind = null): array
    {
        [$where, $bindings] = $this->vaultWhere($search, $kind);
        $roles = array_map(static fn(AssetRole $role): string => $role->value, AssetRole::preserved());
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $rows = $this->database->fetch(new Query(
            'SELECT m."id",
                (SELECT a."kind" FROM "assets" a
                    WHERE a."mediaItemId" = m."id" AND a."role" = ? AND a."state" = ?
                    AND a."broadcastId" IS NULL AND a."broadcastItemId" IS NULL
                    ORDER BY a."createdAt" ASC LIMIT 1) AS kind,
                (SELECT COALESCE(SUM(a."sizeBytes"), 0) FROM "assets" a
                    WHERE a."mediaItemId" = m."id" AND a."role" IN (' . $placeholders . ')
                    AND a."state" = ? AND a."broadcastId" IS NULL AND a."broadcastItemId" IS NULL) AS preserved_size_bytes,
                (SELECT COUNT(DISTINCT si."stashId") FROM "stash_items" si WHERE si."mediaItemId" = m."id") AS stash_count,
                (SELECT COUNT(DISTINCT bi."broadcastId") FROM "broadcast_items" bi WHERE bi."mediaItemId" = m."id") AS broadcast_count
             FROM "media_items" m'
                . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
                . ' ORDER BY m."createdAt" DESC, m."id" DESC LIMIT ? OFFSET ?',
            [
                AssetRole::VaultOriginal->value,
                AssetState::Ready->value,
                ...$roles,
                AssetState::Ready->value,
                ...$bindings,
                $limit,
                $offset,
            ],
        ));

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }

        $items = $this->listByIds($ids);
        $summaries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;

            if (! is_string($id) || ! isset($items[$id])) {
                continue;
            }

            $summaries[] = new VaultItemSummary(
                item: $items[$id],
                kind: is_string($row['kind'] ?? null) ? $row['kind'] : null,
                stashCount: self::integer($row['stash_count'] ?? null),
                broadcastCount: self::integer($row['broadcast_count'] ?? null),
                preservedSizeBytes: self::integer($row['preserved_size_bytes'] ?? null),
            );
        }

        return $summaries;
    }

    public function countVaultSummary(?string $search = null, ?string $kind = null): int
    {
        [$where, $bindings] = $this->vaultWhere($search, $kind);
        $row = $this->database->fetchFirst(new Query(
            'SELECT COUNT(*) AS count FROM "media_items" m' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $bindings,
        ));

        return is_array($row) ? self::integer($row['count'] ?? null) : 0;
    }

    public function totalPreservedSizeBytes(): int
    {
        $roles = array_map(static fn(AssetRole $role): string => $role->value, AssetRole::preserved());
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $row = $this->database->fetchFirst(new Query(
            'SELECT COALESCE(SUM("sizeBytes"), 0) AS total
             FROM "assets"
             WHERE "mediaItemId" IS NOT NULL AND "role" IN (' . $placeholders . ')
                AND "state" = ? AND "broadcastId" IS NULL AND "broadcastItemId" IS NULL',
            [...$roles, AssetState::Ready->value],
        ));

        return is_array($row) ? self::integer($row['total'] ?? null) : 0;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, MediaItemRecord> keyed by id
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];

        /** @var list<MediaItemRecord> $items */
        $items = array_values(MediaItemRecord::select()->whereIn('id', $ids)->all());

        foreach ($items as $item) {
            $byId[(string) $item->id] = $item;
        }

        return $byId;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function vaultWhere(?string $search, ?string $kind): array
    {
        $where = [];
        $bindings = [];

        if ($search !== null && $search !== '') {
            $where[] = 'm."title" ILIKE ?';
            $bindings[] = '%' . $search . '%';
        }

        if ($kind !== null && $kind !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM "assets" a WHERE a."mediaItemId" = m."id" AND a."role" = ? AND a."state" = ? AND a."broadcastId" IS NULL AND a."broadcastItemId" IS NULL AND a."kind" = ?)';
            array_push($bindings, AssetRole::VaultOriginal->value, AssetState::Ready->value, $kind);
        }

        return [$where, $bindings];
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : 0;
    }
}

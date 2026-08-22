<?php

declare(strict_types=1);

namespace App\Vault;

use App\Providers\StashdUri;
use App\Support\DurationSeconds;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class MediaItemRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
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
    ): MediaItemRecord {
        $id = $this->ids->generate('media')->toString();
        $record = new MediaItemRecord(
            providerKey: $providerKey,
            providerItemId: $providerItemId,
            canonicalUri: $canonicalUri instanceof StashdUri ? $canonicalUri->toString() : $canonicalUri,
            title: $title,
            state: $state,
            upstreamState: UpstreamState::Available,
            description: $description,
            durationSeconds: DurationSeconds::toDuration($durationSeconds),
            publishedAt: $publishedAt,
            thumbnailUri: $thumbnailUri instanceof StashdUri ? $thumbnailUri->toString() : $thumbnailUri,
            contentType: $contentType,
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
        return MediaItemRecord::select()
            ->where('providerKey', $providerKey)
            ->where('providerItemId', $providerItemId)
            ->first();
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

        return $query->all();
    }

    public function count(): int
    {
        return MediaItemRecord::count()->execute();
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

        foreach (MediaItemRecord::select()->whereIn('id', $ids)->all() as $item) {
            $byId[(string) $item->id] = $item;
        }

        return $byId;
    }
}

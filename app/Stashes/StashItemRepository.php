<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Support\PrefixedUlidGenerator;
use App\Vault\MediaItemId;
use App\Vault\MediaItemState;
use Tempest\Database\Builder\QueryBuilders\CountQueryBuilder;
use Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder;
use Tempest\Database\Database;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class StashItemRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
        private Database $database,
    ) {}

    public function create(
        StashId $stashId,
        MediaItemId $mediaItemId,
        ?StashInputId $stashInputId = null,
        StashItemState $state = StashItemState::Active,
        ?int $position = null,
        ?string $ignoredReason = null,
    ): StashItemRecord {
        $id = $this->ids->generate('item')->toString();
        $record = new StashItemRecord(
            stashId: $stashId,
            mediaItemId: $mediaItemId,
            state: $state,
            stashInputId: $stashInputId,
            position: $position,
            ignoredReason: $ignoredReason,
            firstSeenAt: DateTime::now(Timezone::UTC),
            lastSeenAt: DateTime::now(Timezone::UTC),
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(StashItemRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(StashItemId $id): ?StashItemRecord
    {
        return StashItemRecord::findById($id->toPrimaryKey());
    }

    public function findByStashAndMediaItem(StashId $stashId, MediaItemId $mediaItemId): ?StashItemRecord
    {
        return StashItemRecord::select()
            ->where('stashId', $stashId->toString())
            ->where('mediaItemId', $mediaItemId->toString())
            ->first();
    }

    /** @return list<StashItemRecord> */
    public function listForStash(
        StashId $stashId,
        ?int $limit = null,
        ?int $offset = null,
        ?string $search = null,
        ?MediaItemState $status = null,
        bool $includeIgnored = true,
        string $sort = 'position',
        Direction $direction = Direction::ASC,
        ?StashInputId $stashInputId = null,
    ): array {
        $query = $this->filteredQuery($stashId, $search, $status, $includeIgnored)
            ->with('mediaItem');

        if ($stashInputId !== null) {
            $query->where('stashInputId', $stashInputId->toString());
        }

        match ($sort) {
            'title' => $query->orderBy('media_items.title', $direction),
            'published' => $query->orderBy('media_items.publishedAt', $direction),
            'duration' => $query->orderBy('media_items.durationSeconds', $direction),
            'status' => $query->orderBy('media_items.state', $direction),
            // Asset size is a per-media-item aggregate across `assets`, not a
            // plain column -- no join gives us that directly, so this is a
            // correlated subquery via the query builder's raw-order escape
            // hatch rather than a second join.
            'size' => $query->orderByRaw(
                '(SELECT COALESCE(SUM("sizeBytes"), 0) FROM "assets" WHERE "assets"."mediaItemId" = "stash_items"."mediaItemId") ' . $direction->value,
            ),
            default => $query->orderBy('position', $direction),
        };

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        return array_values($query->all());
    }

    public function countIgnoredForStash(StashId $stashId): int
    {
        return StashItemRecord::count()
            ->where('stashId', $stashId->toString())
            ->where('state', StashItemState::Ignored)
            ->execute();
    }

    public function countForStash(
        StashId $stashId,
        ?string $search = null,
        ?MediaItemState $status = null,
        bool $includeIgnored = true,
    ): int {
        return CountQueryBuilder::fromQueryBuilder(
            $this->filteredQuery($stashId, $search, $status, $includeIgnored),
        )->execute();
    }

    /**
     * One GROUP BY aggregate, not a fetch-everything-and-count-in-PHP loop --
     * this is what backs the item table's status summary chips, which need
     * counts across the whole stash regardless of the current page/filter.
     *
     * @return array<string, int> media item state (value) => count
     */
    public function statusCountsForStash(StashId $stashId): array
    {
        // Tried .with('mediaItem') + .raw() + .groupBy() first, to reuse the
        // relation's join instead of hand-writing it -- doesn't work: .raw()
        // appends its fragment after the compiled GROUP BY clause, not into
        // the SELECT column list, and the builder always selects full model
        // columns for hydration with no way to override that. There's no
        // query-builder path to an arbitrary `state, COUNT(*)` projection --
        // this is the one piece of this repository that's a genuine grouped
        // aggregate, not a plain filter/sort, and the builder has no answer
        // for that. Everything else in this class (list/count/sort/search)
        // stays fully relation-based; this is the deliberate exception.
        /** @var list<array{state: string, count: int}> $rows */
        $rows = $this->database->fetch(new Query(
            'SELECT "media_items"."state" AS state, COUNT(*) AS count
             FROM "stash_items"
             JOIN "media_items" ON "media_items"."id" = "stash_items"."mediaItemId"
             WHERE "stash_items"."stashId" = ?
             GROUP BY "media_items"."state"',
            [$stashId->toString()],
        ));

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['state']] = $row['count'];
        }

        return $counts;
    }

    /** @return SelectQueryBuilder<StashItemRecord> */
    private function filteredQuery(
        StashId $stashId,
        ?string $search,
        ?MediaItemState $status,
        bool $includeIgnored,
    ): SelectQueryBuilder {
        $query = StashItemRecord::select()->where('stashId', $stashId->toString());

        if (! $includeIgnored) {
            // Qualified: stash_items.state and media_items.state both exist
            // once the mediaItem join is in play (added below by sort, or by
            // listForStash's ->with('mediaItem')) -- unqualified `state` is
            // ambiguous SQL as soon as either applies.
            $query->whereNot('state', StashItemState::Ignored);
        }

        if ($search !== null && $search !== '') {
            $query->whereHas('mediaItem', function (SelectQueryBuilder $mediaItemQuery) use ($search): void {
                $mediaItemQuery->whereLike('title', '%' . $search . '%');
            });
        }

        if ($status !== null) {
            $query->whereHas('mediaItem', function (SelectQueryBuilder $mediaItemQuery) use ($status): void {
                $mediaItemQuery->where('state', $status);
            });
        }

        return $query;
    }

    /** @return list<StashItemRecord> */
    public function listForMediaItem(MediaItemId $mediaItemId): array
    {
        return StashItemRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->all();
    }

    /**
     * @param  list<string>  $mediaItemIds
     * @return list<StashItemRecord>
     */
    public function listForMediaItemsExcludingStash(array $mediaItemIds, StashId $excludingStashId): array
    {
        if ($mediaItemIds === []) {
            return [];
        }

        return StashItemRecord::select()
            ->whereIn('mediaItemId', $mediaItemIds)
            ->whereNot('stashId', $excludingStashId->toString())
            ->all();
    }
}

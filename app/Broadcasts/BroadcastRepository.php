<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashId;
use App\Support\PrefixedUlidGenerator;
use InvalidArgumentException;
use Tempest\Database\Builder\QueryBuilders\WhereGroupBuilder;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class BroadcastRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $settings */
    public function create(
        StashId $stashId,
        string $type,
        string $name,
        string $slug,
        BroadcastState $state = BroadcastState::Pending,
        ?array $settings = null,
    ): BroadcastRecord {
        $id = $this->ids->generate('broadcast')->toString();
        $record = new BroadcastRecord(
            stashId: $stashId,
            type: $type,
            name: $name,
            slug: $slug,
            state: $state,
            settings: $settings,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(BroadcastRecord::class)->insert($record)->execute();

        return BroadcastRecord::select()
            ->get(new PrimaryKey($id))
            ?? throw new InvalidArgumentException('Failed to persist broadcast record.');
    }

    public function find(BroadcastId $id): ?BroadcastRecord
    {
        return BroadcastRecord::select()->get($id->toPrimaryKey());
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, BroadcastRecord> keyed by id
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $broadcasts = [];

        /** @var list<BroadcastRecord> $records */
        $records = BroadcastRecord::select()->whereIn('id', $ids)->all();

        foreach ($records as $broadcast) {
            $broadcasts[(string) $broadcast->id] = $broadcast;
        }

        return $broadcasts;
    }

    public function save(BroadcastRecord $record): BroadcastRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    public function delete(BroadcastRecord $record): void
    {
        $record->delete();
    }

    /** @return list<BroadcastRecord> */
    public function listForStash(StashId $stashId): array
    {
        return BroadcastRecord::select()
            ->where('stashId', $stashId->toString())
            ->orderBy('createdAt', Direction::ASC)
            ->all();
    }

    public function findByStashAndSlug(StashId $stashId, string $slug): ?BroadcastRecord
    {
        return BroadcastRecord::select()
            ->where('stashId', $stashId->toString())
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Returns `$base` if it is free within this stash, otherwise the lowest
     * unused `$base-N` (N starts at 2), so an auto-generated broadcast name
     * doesn't collide the second time a user adds the same type to a stash.
     */
    public function nextAvailableSlug(StashId $stashId, string $base): string
    {
        $taken = array_map(
            static fn(BroadcastRecord $broadcast): string => $broadcast->slug,
            BroadcastRecord::select()
                ->where('stashId', $stashId->toString())
                ->andWhereGroup(fn(WhereGroupBuilder $group) => $group
                    ->where('slug', $base)
                    ->orWhereLike('slug', $base . '-%'))
                ->all(),
        );

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $usedOrdinals = [];

        foreach ($taken as $slug) {
            if (preg_match('/^' . preg_quote($base, '/') . '-(\d+)$/', $slug, $match)) {
                $usedOrdinals[(int) $match[1]] = true;
            }
        }

        $ordinal = 2;

        while (isset($usedOrdinals[$ordinal])) {
            $ordinal++;
        }

        return "{$base}-{$ordinal}";
    }
}

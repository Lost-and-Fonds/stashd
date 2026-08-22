<?php

declare(strict_types=1);

namespace App\Timeline;

use App\Support\PrefixedUlidGenerator;
use App\Vault\MediaItemId;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class TimelineEntryRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @return list<TimelineEntryRecord> */
    public function listForMediaItem(MediaItemId $mediaItemId): array
    {
        $entries = TimelineEntryRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->orderBy('startSeconds', Direction::ASC)
            ->orderBy('endSeconds', Direction::ASC)
            ->all();

        return array_values(array_filter($entries, static fn(mixed $entry): bool => $entry instanceof TimelineEntryRecord));
    }

    public function findBySourceAndExternalId(
        MediaItemId $mediaItemId,
        TimelineEntrySource $source,
        string $externalId,
    ): ?TimelineEntryRecord {
        $entry = TimelineEntryRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->where('source', $source)
            ->where('externalId', $externalId)
            ->first();

        return $entry instanceof TimelineEntryRecord ? $entry : null;
    }

    public function save(TimelineEntryRecord $entry): TimelineEntryRecord
    {
        $entry->updatedAt = DateTime::now(Timezone::UTC);
        $entry->save();

        return $entry;
    }

    /** @param array<string, mixed>|null $raw */
    public function create(
        MediaItemId $mediaItemId,
        TimelineEntrySource $source,
        TimelineEntryKind $kind,
        TimelineEntryCategory $category,
        float $startSeconds,
        float $endSeconds,
        ?string $title = null,
        ?string $externalId = null,
        ?array $raw = null,
    ): TimelineEntryRecord {
        $entry = new TimelineEntryRecord(
            mediaItemId: $mediaItemId,
            source: $source,
            kind: $kind,
            category: $category,
            startSeconds: $startSeconds,
            endSeconds: $endSeconds,
            title: $title,
            externalId: $externalId,
            raw: $raw,
            lastCheckedAt: DateTime::now(Timezone::UTC),
        );
        $entry->id = new PrimaryKey($this->ids->generate('timeline')->toString());
        $entry->createdAt = DateTime::now(Timezone::UTC);
        $entry->updatedAt = $entry->createdAt;

        query(TimelineEntryRecord::class)->insert($entry)->execute();

        return $entry;
    }
}

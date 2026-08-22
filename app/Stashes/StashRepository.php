<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Support\PrefixedUlidGenerator;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemSourceRepository;
use Tempest\Database\Database;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class StashRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
        private StashItemRepository $stashItems,
        private StashInputRepository $stashInputs,
        private MediaItemSourceRepository $mediaItemSources,
        private MediaItemRepository $mediaItems,
        private Database $database,
    ) {}

    public function create(
        string $name,
        SyncMode $syncMode = SyncMode::Automatic,
        DownloadPolicy $downloadPolicy = DownloadPolicy::Video,
        OrganizationMode $organizationMode = OrganizationMode::Flat,
        ?string $description = null,
        ?string $iconUri = null,
    ): StashRecord {
        $id = $this->ids->generate('stash')->toString();
        $record = new StashRecord(
            name: $name,
            syncMode: $syncMode,
            downloadPolicy: $downloadPolicy,
            organizationMode: $organizationMode,
            state: StashState::Ready,
            description: $description,
            iconUri: $iconUri,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(StashRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(StashId $id): ?StashRecord
    {
        return StashRecord::findById($id->toPrimaryKey());
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, StashRecord> keyed by id
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stashes = [];

        /** @var list<StashRecord> $records */
        $records = StashRecord::select()->whereIn('id', $ids)->all();

        foreach ($records as $stash) {
            $stashes[(string) $stash->id] = $stash;
        }

        return $stashes;
    }

    /** @return list<StashRecord> */
    public function list(): array
    {
        return StashRecord::select()
            ->orderBy('createdAt', Direction::ASC)
            ->all();
    }

    public function update(
        StashRecord $stash,
        ?string $name = null,
        ?string $description = null,
        ?SyncMode $syncMode = null,
        ?DownloadPolicy $downloadPolicy = null,
        ?OrganizationMode $organizationMode = null,
        ?string $iconUri = null,
    ): StashRecord {
        if ($name !== null) {
            $stash->name = $name;
        }

        if ($description !== null) {
            $stash->description = $description;
        }

        if ($syncMode !== null) {
            $stash->syncMode = $syncMode;
        }

        if ($downloadPolicy !== null) {
            $stash->downloadPolicy = $downloadPolicy;
        }

        if ($organizationMode !== null) {
            $stash->organizationMode = $organizationMode;
        }

        if ($iconUri !== null) {
            $stash->iconUri = $iconUri;
        }

        $stash->updatedAt = DateTime::now(Timezone::UTC);
        $stash->save();

        return $stash;
    }

    /**
     * Deletes the stash. `stash_items`, `stash_inputs`, and `broadcasts` cascade
     * at the database level (see CreateDomainSchema); media item sources do not
     * (their FK is ON DELETE SET NULL, not CASCADE, since they're meant to be
     * orphan-tracked rather than dropped by default), so they're deleted here
     * explicitly. Deduped `media_items` and Vault originals are left intact.
     *
     * Runs in a transaction: without it, a failure between the sources cleanup
     * and the stash row's own delete (e.g. a write-lock conflict with a
     * background job) would leave the stash orphaned from its sources with no
     * clean way to retry.
     */
    public function delete(StashRecord $stash): void
    {
        $stashId = StashId::fromPrimaryKey($stash->id);

        $committed = $this->database->withinTransaction(function () use ($stash, $stashId): void {
            foreach ($this->stashInputs->listForStash($stashId) as $input) {
                $this->mediaItemSources->deleteForStashInput(StashInputId::fromPrimaryKey($input->id));
            }

            $stash->delete();
        });

        if (! $committed) {
            throw new StashDeleteFailed($stash);
        }
    }

    /**
     * For this stash's items, reports which media items are still referenced by
     * other stashes (shared) versus which would become orphaned in the Vault.
     *
     * @return array{sharedItems: list<array<string, mixed>>, orphanedItems: list<array<string, mixed>>}
     */
    public function deleteImpact(StashRecord $stash): array
    {
        $stashId = StashId::fromPrimaryKey($stash->id);

        $mediaItemIds = array_values(array_unique(array_map(
            static fn(StashItemRecord $item): string => (string) $item->mediaItemId,
            $this->stashItems->listForStash($stashId),
        )));

        if ($mediaItemIds === []) {
            return ['sharedItems' => [], 'orphanedItems' => []];
        }

        $otherStashIdsByMediaItemId = [];

        foreach ($this->stashItems->listForMediaItemsExcludingStash($mediaItemIds, $stashId) as $otherItem) {
            $otherStashIdsByMediaItemId[(string) $otherItem->mediaItemId][(string) $otherItem->stashId] = true;
        }

        $otherStashIds = [];

        foreach ($otherStashIdsByMediaItemId as $idsByStashId) {
            $otherStashIds += $idsByStashId;
        }

        $stashNamesById = array_map(
            static fn(StashRecord $stash): string => $stash->name,
            $this->listByIds(array_keys($otherStashIds)),
        );
        $mediaItemsById = $this->mediaItems->listByIds($mediaItemIds);

        $sharedItems = [];
        $orphanedItems = [];

        foreach ($mediaItemIds as $mediaItemId) {
            $title = $mediaItemsById[$mediaItemId]->title ?? $mediaItemId;
            $otherStashIds = array_keys($otherStashIdsByMediaItemId[$mediaItemId] ?? []);

            if ($otherStashIds === []) {
                $orphanedItems[] = ['mediaItemId' => $mediaItemId, 'title' => $title];

                continue;
            }

            $sharedItems[] = [
                'mediaItemId' => $mediaItemId,
                'title' => $title,
                'sharedWithStashes' => array_map(
                    static fn(string $otherStashId): array => ['id' => $otherStashId, 'name' => $stashNamesById[$otherStashId] ?? 'Unknown stash'],
                    $otherStashIds,
                ),
            ];
        }

        return ['sharedItems' => $sharedItems, 'orphanedItems' => $orphanedItems];
    }
}

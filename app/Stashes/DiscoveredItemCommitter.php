<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Http\Api\ApiJson;
use App\Providers\InputOption;
use App\Providers\ProviderDates;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Support\DurationSeconds;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemSourceRepository;
use App\Vault\UpstreamState;

use function Tempest\Support\str;

/**
 * Persists a discovery result into a stash input: creates the items,
 * sources and stash items that are missing, and reports what was new.
 *
 * Sole owner of "what counts as an item we already have" -- both the initial
 * commit of an input and every later sync route through here, so the two can
 * never drift apart on which items they consider new.
 *
 * Callers are responsible for the surrounding transaction.
 */
final readonly class DiscoveredItemCommitter
{
    public function __construct(
        private ItemRepository $items,
        private ItemSourceRepository $itemSources,
        private StashItemRepository $stashItems,
        private StashInputFilter $inputFilter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $discoveredItems
     * @param  list<InputOption>  $declaredInputOptions
     */
    public function commit(
        StashId $stashId,
        StashInputId $stashInputId,
        ResolvedInput $resolved,
        array $discoveredItems,
        ?StashInputOptions $inputOptions,
        array $declaredInputOptions,
    ): DiscoveredItemCommitCounts {
        $itemsCreated = 0;
        $itemsReused = 0;
        $stashItemsCreated = 0;
        $stashItemsReused = 0;

        /** @var list<string> $downloadableItemIds */
        $downloadableItemIds = [];

        foreach ($discoveredItems as $index => $discoveredItem) {

            $providerItemId = str(ApiJson::string($discoveredItem['provider_item_id'] ?? null))->trim()->toString();
            $canonicalUriRaw = str(ApiJson::string($discoveredItem['canonical_uri'] ?? null))->trim()->toString();
            $title = str(ApiJson::string($discoveredItem['title'] ?? null, 'Untitled'))->trim()->toString();
            $description = is_string($discoveredItem['description'] ?? null) && str($discoveredItem['description'])->trim()->isNotEmpty()
                ? str($discoveredItem['description'])->trim()->toString()
                : null;

            if ($providerItemId === '' || $canonicalUriRaw === '') {
                continue;
            }

            $canonicalUri = StashdUri::parse($canonicalUriRaw);

            $existingMedia = $this->items->findByProviderIdentity($resolved->providerKey, $providerItemId);

            if ($existingMedia === null) {
                $item = $this->items->create(
                    providerKey: $resolved->providerKey,
                    providerItemId: $providerItemId,
                    canonicalUri: $canonicalUri,
                    title: $title,
                    description: $description,
                    durationSeconds: ApiJson::integer($discoveredItem['duration_seconds'] ?? null),
                    publishedAt: ProviderDates::tryParse(is_string($discoveredItem['published_at'] ?? null) ? $discoveredItem['published_at'] : null),
                    thumbnailUri: is_string($discoveredItem['thumbnail_uri'] ?? null) && str($discoveredItem['thumbnail_uri'])->trim()->isNotEmpty()
                    ? StashdUri::parse(str($discoveredItem['thumbnail_uri'])->trim()->toString())
                    : null,
                    contentType: is_string($discoveredItem['content_type'] ?? null) ? $discoveredItem['content_type'] : null,
                    sizeBytes: ApiJson::integer($discoveredItem['size_bytes'] ?? null),
                    sizeEstimated: (bool) ($discoveredItem['size_estimated'] ?? false),
                    upstreamState: UpstreamState::tryFrom(ApiJson::string($discoveredItem['upstream_state'] ?? null)) ?? UpstreamState::Available,
                );
                $itemsCreated++;
            } else {
                $item = $existingMedia;
                $itemsReused++;
                $changed = false;

                $upstreamState = UpstreamState::tryFrom(ApiJson::string($discoveredItem['upstream_state'] ?? null));

                if ($upstreamState !== null && $item->upstreamState !== $upstreamState) {
                    $item->upstreamState = $upstreamState;
                    $changed = true;
                }

                if ($item->sizeBytes === null && isset($discoveredItem['size_bytes'])) {
                    $item->sizeBytes = ApiJson::integer($discoveredItem['size_bytes']);
                    $item->sizeEstimated = (bool) ($discoveredItem['size_estimated'] ?? false);
                    $changed = true;
                }

                if ($item->publishedAt === null && is_string($discoveredItem['published_at'] ?? null)) {
                    $item->publishedAt = ProviderDates::tryParse($discoveredItem['published_at']);
                    $changed = $item->publishedAt !== null || $changed;
                }

                if ($item->duration === null && isset($discoveredItem['duration_seconds'])) {
                    $item->duration = DurationSeconds::toDuration(ApiJson::integer($discoveredItem['duration_seconds']));
                    $changed = true;
                }

                if ($item->thumbnailUri === null && is_string($discoveredItem['thumbnail_uri'] ?? null)) {
                    $item->thumbnailUri = $discoveredItem['thumbnail_uri'];
                    $changed = true;
                }

                if ($changed) {
                    $this->items->save($item);
                }
            }

            $itemId = ItemId::fromPrimaryKey($item->id);

            if ($this->itemSources->findForItemAndInput($itemId, $stashInputId) === null) {
                $this->itemSources->create(
                    itemId: $itemId,
                    providerKey: $resolved->providerKey,
                    providerInputId: $resolved->providerInputId,
                    discoveredUri: $canonicalUri->toString(),
                    stashInputId: $stashInputId,
                    position: $index + 1,
                );
            }

            if ($this->stashItems->findByStashAndItem($stashId, $itemId) === null) {
                $contentType = is_string($discoveredItem['content_type'] ?? null) ? $discoveredItem['content_type'] : null;
                $ignoredReason = $this->inputFilter->ignoredReason($title, $contentType, $inputOptions, $declaredInputOptions);

                $stashItem = $this->stashItems->create(
                    stashId: $stashId,
                    itemId: $itemId,
                    stashInputId: $stashInputId,
                    position: $index + 1,
                    ignoredReason: $ignoredReason,
                    state: $ignoredReason !== null ? StashItemState::Ignored : StashItemState::Active,
                );
                $stashItemsCreated++;

                if ($stashItem->state !== StashItemState::Ignored && $item->upstreamState === UpstreamState::Available) {
                    $downloadableItemIds[] = $itemId->toString();
                }
            } else {
                $stashItemsReused++;
            }
        }

        return new DiscoveredItemCommitCounts(
            itemsCreated: $itemsCreated,
            itemsReused: $itemsReused,
            stashItemsCreated: $stashItemsCreated,
            stashItemsReused: $stashItemsReused,
            downloadableItemIds: $downloadableItemIds,
        );
    }
}

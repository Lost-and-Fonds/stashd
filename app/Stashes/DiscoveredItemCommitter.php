<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Providers\InputOption;
use App\Providers\ProviderDates;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemSourceRepository;

use function Tempest\Support\str;

/**
 * Persists a discovery result into a stash input: creates the media items,
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
        private MediaItemRepository $mediaItems,
        private MediaItemSourceRepository $mediaItemSources,
        private StashItemRepository $stashItems,
        private StashInputFilter $inputFilter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $discoveredItems
     * @param list<InputOption> $declaredInputOptions
     */
    public function commit(
        StashId $stashId,
        StashInputId $stashInputId,
        ResolvedInput $resolved,
        array $discoveredItems,
        ?StashInputOptions $inputOptions,
        array $declaredInputOptions,
    ): DiscoveredItemCommitCounts {
        $mediaItemsCreated = 0;
        $mediaItemsReused = 0;
        $stashItemsCreated = 0;
        $stashItemsReused = 0;

        /** @var list<string> $downloadableMediaItemIds */
        $downloadableMediaItemIds = [];

        foreach (array_values($discoveredItems) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $providerItemId = str((string) ($item['provider_item_id'] ?? ''))->trim()->toString();
            $canonicalUriRaw = str((string) ($item['canonical_uri'] ?? ''))->trim()->toString();
            $title = str((string) ($item['title'] ?? 'Untitled'))->trim()->toString();
            $description = is_string($item['description'] ?? null) && str($item['description'])->trim()->isNotEmpty()
                ? str($item['description'])->trim()->toString()
                : null;

            if ($providerItemId === '' || $canonicalUriRaw === '') {
                continue;
            }

            $canonicalUri = StashdUri::parse($canonicalUriRaw);

            $existingMedia = $this->mediaItems->findByProviderIdentity($resolved->providerKey, $providerItemId);

            if ($existingMedia === null) {
                $mediaItem = $this->mediaItems->create(
                    providerKey: $resolved->providerKey,
                    providerItemId: $providerItemId,
                    canonicalUri: $canonicalUri,
                    title: $title,
                    description: $description,
                    durationSeconds: isset($item['duration_seconds']) ? (int) $item['duration_seconds'] : null,
                    publishedAt: ProviderDates::tryParse(is_string($item['published_at'] ?? null) ? $item['published_at'] : null),
                    thumbnailUri: is_string($item['thumbnail_uri'] ?? null) && str($item['thumbnail_uri'])->trim()->isNotEmpty()
                    ? StashdUri::parse(str($item['thumbnail_uri'])->trim()->toString())
                    : null,
                    contentType: is_string($item['content_type'] ?? null) ? $item['content_type'] : null,
                );
                $mediaItemsCreated++;
            } else {
                $mediaItem = $existingMedia;
                $mediaItemsReused++;
            }

            $mediaItemId = MediaItemId::fromPrimaryKey($mediaItem->id);

            if ($this->mediaItemSources->findForMediaItemAndInput($mediaItemId, $stashInputId) === null) {
                $this->mediaItemSources->create(
                    mediaItemId: $mediaItemId,
                    providerKey: $resolved->providerKey,
                    providerInputId: $resolved->providerInputId,
                    discoveredUri: $canonicalUri->toString(),
                    stashInputId: $stashInputId,
                    position: $index + 1,
                );
            }

            if ($this->stashItems->findByStashAndMediaItem($stashId, $mediaItemId) === null) {
                $contentType = is_string($item['content_type'] ?? null) ? $item['content_type'] : null;
                $ignoredReason = $this->inputFilter->ignoredReason($title, $contentType, $inputOptions, $declaredInputOptions);

                $stashItem = $this->stashItems->create(
                    stashId: $stashId,
                    mediaItemId: $mediaItemId,
                    stashInputId: $stashInputId,
                    position: $index + 1,
                    ignoredReason: $ignoredReason,
                    state: $ignoredReason !== null ? StashItemState::Ignored : StashItemState::Active,
                );
                $stashItemsCreated++;

                if ($stashItem->state !== StashItemState::Ignored) {
                    $downloadableMediaItemIds[] = $mediaItemId->toString();
                }
            } else {
                $stashItemsReused++;
            }
        }

        return new DiscoveredItemCommitCounts(
            mediaItemsCreated: $mediaItemsCreated,
            mediaItemsReused: $mediaItemsReused,
            stashItemsCreated: $stashItemsCreated,
            stashItemsReused: $stashItemsReused,
            downloadableMediaItemIds: $downloadableMediaItemIds,
        );
    }
}

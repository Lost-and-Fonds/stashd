<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashInputRepository;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemRepository;
use App\Stashes\StashItemState;
use App\Stashes\StashRepository;
use App\Vault\AssetRepository;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;

final readonly class BroadcastContextFactory
{
    public function __construct(
        private BroadcastRepository $broadcasts,
        private StashRepository $stashes,
        private StashItemRepository $stashItems,
        private StashInputRepository $stashInputs,
        private MediaItemRepository $mediaItems,
        private AssetRepository $assets,
    ) {}

    public function build(BroadcastRecord $broadcast): BroadcastContext
    {
        $stashId = $broadcast->stashId;

        $stash = $this->stashes->find($stashId)
            ?? throw BroadcastException::withCode('stash_not_found', 'Stash not found.');

        $this->migrateLegacySourceSettings($broadcast);

        $stashItems = $this->stashItems->listForStash($stashId);

        $mediaItems = [];
        $readyMediaItemIds = [];

        foreach ($stashItems as $stashItem) {
            $mediaItemId = (string) $stashItem->mediaItemId;
            $mediaItem = isset($stashItem->mediaItem)
                ? $stashItem->mediaItem
                : $this->mediaItems->find($stashItem->mediaItemId);

            if ($mediaItem === null) {
                continue;
            }

            $mediaItems[$mediaItemId] = $mediaItem;

            if ($mediaItem->state === MediaItemState::Ready) {
                $readyMediaItemIds[] = $mediaItemId;
            }
        }

        $readyVaultOriginals = $this->assets->readyVaultOriginalsByMediaItem($readyMediaItemIds);
        $vaultOriginals = [];

        foreach ($mediaItems as $mediaItemId => $mediaItem) {
            $vaultOriginals[$mediaItemId] = $mediaItem->state === MediaItemState::Ready
                ? $readyVaultOriginals[$mediaItemId] ?? null
                : null;
        }

        return new BroadcastContext(
            broadcast: $broadcast,
            stash: $stash,
            stashItems: $stashItems,
            mediaItems: $mediaItems,
            vaultOriginals: $vaultOriginals,
            stashInputs: $this->stashInputs->listForStash($stashId),
        );
    }

    private function migrateLegacySourceSettings(BroadcastRecord $broadcast): void
    {
        $settings = $broadcast->settings ?? [];
        $legacy = $settings['season_mapping'] ?? null;
        if (! is_array($legacy)) {
            return;
        }

        $sourceSettings = is_array($settings['source_settings'] ?? null) ? $settings['source_settings'] : [];
        foreach ($legacy as $reference => $value) {
            if (is_string($reference) && $reference !== '' && is_int($value) && $value > 0) {
                $sourceSettings[$reference] ??= ['season' => $value];
            }
        }
        $settings['source_settings'] = $sourceSettings;
        unset($settings['season_mapping']);
        $broadcast->settings = $settings;
        $this->broadcasts->save($broadcast);
    }

    /** @return list<StashItemRecord> */
    public function publishableStashItems(BroadcastContext $context): array
    {
        $items = [];

        foreach ($context->stashItems as $stashItem) {
            if ($stashItem->state !== StashItemState::Active) {
                continue;
            }

            $vault = $context->vaultOriginals[(string) $stashItem->mediaItemId] ?? null;

            if ($vault === null) {
                continue;
            }

            $items[] = $stashItem;
        }

        return $items;
    }
}

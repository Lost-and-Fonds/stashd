<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashInputRepository;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemRepository;
use App\Stashes\StashItemState;
use App\Stashes\StashRepository;
use App\Vault\AssetRepository;
use App\Vault\ItemRepository;
use App\Vault\ItemState;

final readonly class BroadcastContextFactory
{
    public function __construct(
        private BroadcastRepository $broadcasts,
        private StashRepository $stashes,
        private StashItemRepository $stashItems,
        private StashInputRepository $stashInputs,
        private ItemRepository $items,
        private AssetRepository $assets,
    ) {}

    public function build(BroadcastRecord $broadcast): BroadcastContext
    {
        $stashId = $broadcast->stashId;

        $stash = $this->stashes->find($stashId)
            ?? throw BroadcastException::withCode('stash_not_found', 'Stash not found.');

        $this->migrateLegacySourceSettings($broadcast);

        $stashItems = $this->stashItems->listForStash($stashId);

        $items = [];
        $readyItemIds = [];

        foreach ($stashItems as $stashItem) {
            $itemId = (string) $stashItem->itemId;
            $item = isset($stashItem->item)
                ? $stashItem->item
                : $this->items->find($stashItem->itemId);

            if ($item === null) {
                continue;
            }

            $items[$itemId] = $item;

            if ($item->state === ItemState::Ready) {
                $readyItemIds[] = $itemId;
            }
        }

        $readyVaultOriginals = $this->assets->readyVaultOriginalsByItem($readyItemIds);
        $vaultOriginals = [];

        foreach ($items as $itemId => $item) {
            $vaultOriginals[$itemId] = $item->state === ItemState::Ready
                ? $readyVaultOriginals[$itemId] ?? null
                : null;
        }

        return new BroadcastContext(
            broadcast: $broadcast,
            stash: $stash,
            stashItems: $stashItems,
            items: $items,
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

            $vault = $context->vaultOriginals[(string) $stashItem->itemId] ?? null;

            if ($vault === null) {
                continue;
            }

            $items[] = $stashItem;
        }

        return $items;
    }
}

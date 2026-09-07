<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashId;
use App\Stashes\StashItemId;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\Vault\ItemId;
use App\Vault\ItemRepository;

test('StashItemRecord::stashId/itemId round-trip as typed IDs through insert, where-lookup, and reload', function (): void {
    $stashes = $this->container->get(StashRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $stashItems = $this->container->get(StashItemRepository::class);

    $stash = $stashes->create('Typed ID Stash');
    $item = $items->create(
        providerKey: 'fake',
        providerItemId: 'typed-id-item',
        canonicalUri: 'fake://item/typed-id-item',
        title: 'Typed ID Item',
    );
    $stashId = StashId::parse((string) $stash->id);
    $itemId = ItemId::parse((string) $item->id);

    $created = $stashItems->create(stashId: $stashId, itemId: $itemId);

    expect($created->stashId)->toBeInstanceOf(StashId::class)
        ->and($created->stashId->toString())->toBe($stashId->toString())
        ->and($created->itemId)->toBeInstanceOf(ItemId::class)
        ->and($created->itemId->toString())->toBe($itemId->toString());

    // Multi-column WHERE lookup on both typed-ID-backed columns: the property
    // caster only fixes hydration/persistence, not raw bound-param binding,
    // so this proves findByStashAndItem's explicit ->toString() calls work.
    $found = $stashItems->findByStashAndItem($stashId, $itemId);
    expect($found)->not->toBeNull()
        ->and((string) $found->id)->toBe((string) $created->id);

    $reloaded = $stashItems->find(StashItemId::parse((string) $created->id));
    expect($reloaded?->stashId)->toBeInstanceOf(StashId::class)
        ->and($reloaded?->stashId->toString())->toBe($stashId->toString())
        ->and($reloaded?->itemId)->toBeInstanceOf(ItemId::class)
        ->and($reloaded?->itemId->toString())->toBe($itemId->toString());
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashId;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRecord;
use App\Stashes\StashRepository;
use App\Vault\ItemId;
use App\Vault\ItemRepository;

/*
 * Proves Tempest declared relations (#[HasMany]/#[BelongsTo]) hydrate on
 * PostgreSQL, where the FK constraint and the relation join are independent
 * mechanisms.
 */

beforeEach(function (): void {
    $stashes = $this->container->get(StashRepository::class);
    $items = $this->container->get(StashItemRepository::class);
    $media = $this->container->get(ItemRepository::class);

    $this->stash = $stashes->create(name: 'Relations Spike');
    $this->stashId = StashId::fromPrimaryKey($this->stash->id);

    foreach (['one', 'two'] as $key) {
        $item = $media->create(
            providerKey: 'fake',
            providerItemId: "relations-{$key}",
            canonicalUri: "fake://item/relations-{$key}",
            title: "Relations {$key}",
        );
        $items->create(stashId: $this->stashId, itemId: ItemId::fromPrimaryKey($item->id));
    }
});

test('HasMany eager-loads stash items via with() on PostgreSQL', function (): void {
    $loaded = StashRecord::select()->with('items')->get($this->stash->id);

    expect($loaded)->not->toBeNull()
        ->and($loaded->items)->toHaveCount(2)
        ->and($loaded->items[0])->toBeInstanceOf(StashItemRecord::class)
        ->and((string) $loaded->items[0]->stashId)->toBe((string) $this->stash->id);
});

test('HasMany loads on demand via load()', function (): void {
    $reloaded = StashRecord::select()->get($this->stash->id);
    $reloaded->load('items');

    expect($reloaded->items)->toHaveCount(2);
});

test('BelongsTo eager-loads the owning stash from an item', function (): void {
    $item = StashItemRecord::select()
        ->with('stash')
        ->where('stashId', $this->stashId->toString())
        ->first();

    expect($item)->not->toBeNull()
        ->and($item->stash)->toBeInstanceOf(StashRecord::class)
        ->and($item->stash->name)->toBe('Relations Spike');
});

test('whereHas filters stashes by related items', function (): void {
    $matching = StashRecord::select()
        ->whereHas('items', fn($query) => $query->where('stashId', $this->stashId->toString()))
        ->all();

    expect($matching)->toHaveCount(1)
        ->and((string) $matching[0]->id)->toBe((string) $this->stash->id);
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashId;
use App\Stashes\StashItemRepository;
use App\Stashes\StashItemState;
use App\Stashes\StashRepository;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use Tempest\Database\Direction;

beforeEach(function (): void {
    $stashes = $this->container->get(StashRepository::class);
    $this->items = $this->container->get(StashItemRepository::class);
    $media = $this->container->get(ItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);

    $stash = $stashes->create(name: 'Filter Spike');
    $this->stashId = StashId::fromPrimaryKey($stash->id);

    $seed = [
        ['title' => 'Alpha Episode', 'state' => ItemState::Ready, 'sizeBytes' => 300],
        ['title' => 'Beta Episode', 'state' => ItemState::Failed, 'sizeBytes' => null],
        ['title' => 'Gamma Special', 'state' => ItemState::Ready, 'sizeBytes' => 100],
    ];

    foreach ($seed as $key => $row) {
        $item = $media->create(
            providerKey: 'fake',
            providerItemId: "filter-spike-{$key}",
            canonicalUri: "fake://item/filter-spike-{$key}",
            title: $row['title'],
            state: $row['state'],
        );
        $this->items->create(stashId: $this->stashId, itemId: ItemId::fromPrimaryKey($item->id), position: $key);

        if ($row['sizeBytes'] !== null) {
            $assets->create(
                itemId: ItemId::fromPrimaryKey($item->id),
                role: AssetRole::Hardlink,
                kind: AssetKind::Video,
                state: AssetState::Ready,
                sizeBytes: $row['sizeBytes'],
            );
        }
    }

    $ignoredItem = $media->create(
        providerKey: 'fake',
        providerItemId: 'filter-spike-ignored',
        canonicalUri: 'fake://item/filter-spike-ignored',
        title: 'Ignored Premiere',
        state: ItemState::Discovered,
        contentType: 'premiere',
    );
    $this->items->create(
        stashId: $this->stashId,
        itemId: ItemId::fromPrimaryKey($ignoredItem->id),
        state: StashItemState::Ignored,
        position: 99,
        ignoredReason: 'filter_video_type',
    );
});

test('search filters by joined item title', function (): void {
    $results = $this->items->listForStash($this->stashId, search: 'Gamma');

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->title)->toBe('Gamma Special');
});

test('status filters by joined item state', function (): void {
    $results = $this->items->listForStash($this->stashId, status: ItemState::Failed);

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->title)->toBe('Beta Episode');
});

test('sort by title orders on the joined column', function (): void {
    $results = $this->items->listForStash($this->stashId, sort: 'title', direction: Direction::ASC, includeIgnored: false);

    expect(array_map(fn($item) => $item->item->title, $results))
        ->toBe(['Alpha Episode', 'Beta Episode', 'Gamma Special']);
});

test('sort by size orders via the correlated asset-size subquery', function (): void {
    $results = $this->items->listForStash($this->stashId, sort: 'size', direction: Direction::ASC, includeIgnored: false);

    // Beta has no asset at all (0), Gamma is 100 bytes, Alpha is 300 bytes.
    expect(array_map(fn($item) => $item->item->title, $results))
        ->toBe(['Beta Episode', 'Gamma Special', 'Alpha Episode']);
});

test('includeIgnored: false excludes ignored stash items', function (): void {
    $results = $this->items->listForStash($this->stashId, includeIgnored: false);

    expect($results)->toHaveCount(3)
        ->and(array_map(fn($item) => $item->item->title, $results))->not->toContain('Ignored Premiere');
});

test('countForStash respects the same filters as listForStash', function (): void {
    expect($this->items->countForStash($this->stashId))->toBe(4)
        ->and($this->items->countForStash($this->stashId, includeIgnored: false))->toBe(3)
        ->and($this->items->countForStash($this->stashId, status: ItemState::Ready))->toBe(2)
        ->and($this->items->countForStash($this->stashId, search: 'Beta'))->toBe(1);
});

test('statusCountsForStash returns a real grouped aggregate', function (): void {
    $counts = $this->items->statusCountsForStash($this->stashId);

    expect($counts)->toEqualCanonicalizing([
        'ready' => 2,
        'failed' => 1,
        'discovered' => 1,
    ]);
});

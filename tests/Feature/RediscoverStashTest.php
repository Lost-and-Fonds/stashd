<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\RediscoverStash;
use App\Vault\ItemId;
use App\Vault\ItemRepository;

test('rediscover fills missing discovery metadata without overwriting saved values', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('rediscover-metadata');
    $items = $this->container->get(ItemRepository::class);
    $item = $items->find(ItemId::parse($itemId));
    $item->description = null;
    $item->durationSeconds = null;
    $item->publishedAt = null;
    $item->title = 'Saved title';
    $items->save($item);

    $result = $this->container->get(RediscoverStash::class)->execute($stashId);
    $reloaded = $items->find(ItemId::parse($itemId));

    expect($result)->toMatchArray(['inputs' => 1, 'discovered' => 3, 'matched' => 3, 'updated' => 1, 'fields' => 3])
        ->and($reloaded->title)->toBe('Saved title')
        ->and($reloaded->description)->toBe('Fake episode 1 description.')
        ->and((int) $reloaded->durationSeconds?->getTotalSeconds())->toBe(630)
        ->and($reloaded->publishedAt?->toRfc3339(useZ: true))->toBe('2026-01-01T12:00:00Z');
});

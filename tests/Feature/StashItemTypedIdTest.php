<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashId;
use App\Stashes\StashItemId;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;

test('StashItemRecord::stashId/mediaItemId round-trip as typed IDs through insert, where-lookup, and reload', function (): void {
    $stashes = $this->container->get(StashRepository::class);
    $mediaItems = $this->container->get(MediaItemRepository::class);
    $stashItems = $this->container->get(StashItemRepository::class);

    $stash = $stashes->create('Typed ID Stash');
    $mediaItem = $mediaItems->create(
        providerKey: 'fake',
        providerItemId: 'typed-id-item',
        canonicalUri: 'fake://item/typed-id-item',
        title: 'Typed ID Item',
    );
    $stashId = StashId::parse((string) $stash->id);
    $mediaItemId = MediaItemId::parse((string) $mediaItem->id);

    $created = $stashItems->create(stashId: $stashId, mediaItemId: $mediaItemId);

    expect($created->stashId)->toBeInstanceOf(StashId::class)
        ->and($created->stashId->toString())->toBe($stashId->toString())
        ->and($created->mediaItemId)->toBeInstanceOf(MediaItemId::class)
        ->and($created->mediaItemId->toString())->toBe($mediaItemId->toString());

    // Multi-column WHERE lookup on both typed-ID-backed columns: the property
    // caster only fixes hydration/persistence, not raw bound-param binding,
    // so this proves findByStashAndMediaItem's explicit ->toString() calls work.
    $found = $stashItems->findByStashAndMediaItem($stashId, $mediaItemId);
    expect($found)->not->toBeNull()
        ->and((string) $found->id)->toBe((string) $created->id);

    $reloaded = $stashItems->find(StashItemId::parse((string) $created->id));
    expect($reloaded?->stashId)->toBeInstanceOf(StashId::class)
        ->and($reloaded?->stashId->toString())->toBe($stashId->toString())
        ->and($reloaded?->mediaItemId)->toBeInstanceOf(MediaItemId::class)
        ->and($reloaded?->mediaItemId->toString())->toBe($mediaItemId->toString());
});

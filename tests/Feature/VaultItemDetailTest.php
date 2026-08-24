<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use Tempest\Http\Status;

test('vault item detail exposes canonical facts, preserved assets, and memberships', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('vault-detail');
    [, $secondStashId] = $this->bootstrapFakeDownloadStash('vault-detail');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $derived = $this->container->get(AssetRepository::class)->create(
        MediaItemId::parse($mediaItemId),
        AssetRole::Derived,
        AssetKind::Video,
        state: AssetState::Ready,
        sizeBytes: 999,
    );

    $response = $this->http->get("/api/v1/items/{$mediaItemId}", headers: $headers)->assertOk();
    $overview = $this->http->get('/api/v1/items?limit=200', headers: $headers)->assertOk();
    $roles = array_column($response->body['assets'], 'role');
    $overviewRow = array_values(array_filter($overview->body['items'], static fn(array $item): bool => $item['id'] === $mediaItemId))[0];

    expect($response->body)->toHaveKeys(['item', 'assets', 'stashes', 'broadcasts', 'preserved_size_bytes'])
        ->and($response->body['item']['id'])->toBe($mediaItemId)
        ->and(array_column($response->body['stashes'], 'id'))->toContain($stashId, $secondStashId)
        ->and($response->body['stashes'])->toHaveCount(2)
        ->and($response->body['broadcasts'])->toHaveCount(1)
        ->and($response->body['broadcasts'][0]['id'])->toBe($broadcastId)
        ->and($roles)->not->toContain($derived->role->value)
        ->and($response->body['preserved_size_bytes'])->toBeGreaterThan(0)
        ->and($response->body['preserved_size_bytes'])->toBe($overviewRow['preserved_size_bytes']);
});

test('vault item detail returns empty relationships and standard 404s', function (): void {
    [$headers, , $mediaItemId] = $this->bootstrapFakeDownloadStash('vault-detail-empty');

    $response = $this->http->get("/api/v1/items/{$mediaItemId}", headers: $headers)->assertOk();
    expect($response->body['broadcasts'])->toBe([]);

    $this->http->get('/api/v1/items/media_01ARZ3NDEKTSV4RRFFQ69G5FAV', headers: $headers)
        ->assertStatus(Status::NOT_FOUND);
});

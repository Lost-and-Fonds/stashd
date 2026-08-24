<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use Tempest\Http\Status;

/** @return array<string, mixed> */
function vaultRow(array $response, string $mediaItemId): array
{
    foreach ($response['items'] as $item) {
        if ($item['id'] === $mediaItemId) {
            return $item;
        }
    }

    throw new \RuntimeException('Expected media item was absent from Vault overview.');
}

test('vault overview lists one canonical item with distinct stash membership', function (): void {
    [$headers, $firstStash, $mediaItemId] = $this->bootstrapFakeDownloadStash('vault-shared');
    [$unusedHeaders, $secondStash] = $this->bootstrapFakeDownloadStash('vault-shared');

    $response = $this->http->get('/api/v1/items?limit=200', headers: $headers)->assertOk();
    $row = vaultRow($response->body, $mediaItemId);

    expect($row['stash_count'])->toBe(2)
        ->and(array_filter($response->body['items'], static fn(array $item): bool => $item['id'] === $mediaItemId))->toHaveCount(1)
        ->and($firstStash)->not->toBe($secondStash);
});

test('vault overview counts persisted broadcast item membership and excludes derived assets from preserved size', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('vault-broadcast');

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

    $beforeResponse = $this->http->get('/api/v1/items?limit=200', headers: $headers)->assertOk();
    $before = vaultRow($beforeResponse->body, $mediaItemId);
    $assets = $this->container->get(AssetRepository::class);
    $derived = $assets->create(MediaItemId::parse($mediaItemId), AssetRole::Derived, AssetKind::Other, sizeBytes: 999);
    $derived->state = AssetState::Ready;
    $assets->save($derived);
    $afterResponse = $this->http->get('/api/v1/items?limit=200', headers: $headers)->assertOk();
    $after = vaultRow($afterResponse->body, $mediaItemId);

    expect($before['broadcast_count'])->toBe(1)
        ->and($after['broadcast_count'])->toBe(1)
        ->and($after['preserved_size_bytes'])->toBe($before['preserved_size_bytes'])
        ->and($afterResponse->body['preserved_size_bytes'])->toBe($beforeResponse->body['preserved_size_bytes']);
});

test('vault overview filters, paginates, and returns an empty collection honestly', function (): void {
    [$headers, $unusedStashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('vault-search');
    $item = $this->container->get(MediaItemRepository::class)->find(MediaItemId::parse($mediaItemId));
    $query = substr($item?->title ?? '', 0, 8);

    $filtered = $this->http->get('/api/v1/items?limit=1&offset=0&search=' . urlencode($query), headers: $headers)->assertOk();
    $empty = $this->http->get('/api/v1/items?search=not-a-real-vault-title', headers: $headers)->assertOk();

    expect($filtered->body['items'])->toHaveCount(1)
        ->and($filtered->body['limit'])->toBe(1)
        ->and($filtered->body['offset'])->toBe(0)
        ->and($empty->body['items'])->toBe([])
        ->and($empty->body['total'])->toBe(0)
        ->and($empty->body['vault_total'])->toBeGreaterThan(0);
});

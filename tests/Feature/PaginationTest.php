<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashId;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use Tempest\Http\Status;

test('GET /api/v1/stashes/{id}/items paginates with limit/offset and reports total', function (): void {
    $headers = $this->authHeaders();
    $stashes = $this->container->get(StashRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $stashItems = $this->container->get(StashItemRepository::class);

    $stash = $stashes->create('Pagination Stash');
    $stashId = StashId::parse((string) $stash->id);

    for ($i = 1; $i <= 5; $i++) {
        $item = $items->create(
            providerKey: 'fake',
            providerItemId: "pagination-item-{$i}",
            canonicalUri: "fake://item/pagination-item-{$i}",
            title: "Pagination Item {$i}",
        );
        $stashItems->create(
            stashId: $stashId,
            itemId: ItemId::parse((string) $item->id),
            position: $i,
        );
    }

    $page1 = $this->http->get('/api/v1/stashes/' . $stashId . '/items?limit=2&offset=0', headers: $headers)
        ->assertStatus(Status::OK);
    expect($page1->body['items'])->toHaveCount(2)
        ->and($page1->body['total'])->toBe(5)
        ->and($page1->body['limit'])->toBe(2)
        ->and($page1->body['offset'])->toBe(0);

    $page2 = $this->http->get('/api/v1/stashes/' . $stashId . '/items?limit=2&offset=2', headers: $headers)
        ->assertStatus(Status::OK);
    expect($page2->body['items'])->toHaveCount(2)
        ->and($page2->body['total'])->toBe(5)
        ->and($page2->body['offset'])->toBe(2);

    $page1Ids = array_column($page1->body['items'], 'id');
    $page2Ids = array_column($page2->body['items'], 'id');
    expect(array_intersect($page1Ids, $page2Ids))->toBe([]);

    $default = $this->http->get('/api/v1/stashes/' . $stashId . '/items', headers: $headers)
        ->assertStatus(Status::OK);
    expect($default->body['items'])->toHaveCount(5)
        ->and($default->body['limit'])->toBe(50)
        ->and($default->body['offset'])->toBe(0);
});

test('GET /api/v1/stashes/{id}/items caps limit at 200', function (): void {
    $headers = $this->authHeaders();
    $stashes = $this->container->get(StashRepository::class);
    $stash = $stashes->create('Pagination Cap Stash');

    $response = $this->http->get('/api/v1/stashes/' . $stash->id . '/items?limit=999', headers: $headers)
        ->assertStatus(Status::OK);

    expect($response->body['limit'])->toBe(200);
});

test('GET /api/v1/items paginates with limit/offset and reports total', function (): void {
    $headers = $this->authHeaders();
    $items = $this->container->get(ItemRepository::class);

    for ($i = 1; $i <= 5; $i++) {
        $items->create(
            providerKey: 'fake',
            providerItemId: "vault-pagination-item-{$i}",
            canonicalUri: "fake://item/vault-pagination-item-{$i}",
            title: "Vault Pagination Item {$i}",
        );
    }

    $page1 = $this->http->get('/api/v1/items?limit=2&offset=0', headers: $headers)
        ->assertStatus(Status::OK);
    expect($page1->body['items'])->toHaveCount(2)
        ->and($page1->body['limit'])->toBe(2)
        ->and($page1->body['offset'])->toBe(0)
        ->and($page1->body['total'])->toBeGreaterThanOrEqual(5);

    $page2 = $this->http->get('/api/v1/items?limit=2&offset=2', headers: $headers)
        ->assertStatus(Status::OK);
    expect($page2->body['items'])->toHaveCount(2);

    $page1Ids = array_column($page1->body['items'], 'id');
    $page2Ids = array_column($page2->body['items'], 'id');
    expect(array_intersect($page1Ids, $page2Ids))->toBe([]);
});

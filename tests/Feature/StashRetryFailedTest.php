<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use Tempest\Database\Direction;
use Tempest\Http\Status;

test('retry-failed creates independent download jobs for failed items only', function (): void {
    [$headers, $stashIdA] = $this->bootstrapFakeDownloadStash('retry-all-a');
    [$headersB, $stashIdB, $itemIdB] = $this->bootstrapFakeDownloadStash('retry-all-b');

    $itemsA = StashItemRecord::select()
        ->where('stashId', $stashIdA)
        ->orderBy('position', Direction::ASC)
        ->all();
    expect($itemsA)->toHaveCount(3);

    $items = $this->container->get(ItemRepository::class);

    // Two of three items in stash A fail; the third is left untouched so we
    // can prove it's not retried.
    $failedItemIdsA = [(string) $itemsA[0]->itemId, (string) $itemsA[1]->itemId];

    foreach ($failedItemIdsA as $itemId) {
        $item = $items->find(ItemId::parse($itemId));
        $item->state = ItemState::Failed;
        $items->save($item);
    }
    $untouchedItemIdA = (string) $itemsA[2]->itemId;
    $untouchedStateBefore = $items->find(ItemId::parse($untouchedItemIdA))->state;

    // A failed item in a different stash must never be retried by stash A's request.
    $itemB = $items->find(ItemId::parse($itemIdB));
    $itemB->state = ItemState::Failed;
    $items->save($itemB);

    $response = $this->http->post('/api/v1/stashes/' . $stashIdA . '/retry-failed', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    expect($response->body['created_count'])->toBe(2)
        ->and($response->body['jobs'])->toHaveCount(2);

    foreach ($failedItemIdsA as $itemId) {
        expect($items->find(ItemId::parse($itemId))->state)->toBe(ItemState::DownloadPending);
    }

    $this->processAllJobs();

    foreach ($failedItemIdsA as $itemId) {
        expect($items->find(ItemId::parse($itemId))->state)->toBe(ItemState::Ready);
    }

    expect($items->find(ItemId::parse($untouchedItemIdA))->state)->toBe($untouchedStateBefore)
        ->and($items->find(ItemId::parse($itemIdB))->state)->toBe(ItemState::Failed);
});

test('retry-failed rejects an unknown stash id', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/stashes/stash_does_not_exist/retry-failed', [], headers: $headers)->assertStatus(Status::NOT_FOUND);
});

test('retry-failed reports no jobs when nothing failed', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('retry-all-none-failed');

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/retry-failed', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    expect($response->body['created_count'])->toBe(0)
        ->and($response->body['jobs'])->toBe([]);
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use Tempest\Database\Direction;
use Tempest\Http\Status;

test('retry-failed creates independent download jobs for failed items only', function (): void {
    [$headers, $stashIdA] = $this->bootstrapFakeDownloadStash('retry-all-a');
    [$headersB, $stashIdB, $mediaItemIdB] = $this->bootstrapFakeDownloadStash('retry-all-b');

    $itemsA = StashItemRecord::select()
        ->where('stashId', $stashIdA)
        ->orderBy('position', Direction::ASC)
        ->all();
    expect($itemsA)->toHaveCount(3);

    $mediaItems = $this->container->get(MediaItemRepository::class);

    // Two of three items in stash A fail; the third is left untouched so we
    // can prove it's not retried.
    $failedMediaItemIdsA = [(string) $itemsA[0]->mediaItemId, (string) $itemsA[1]->mediaItemId];

    foreach ($failedMediaItemIdsA as $mediaItemId) {
        $mediaItem = $mediaItems->find(MediaItemId::parse($mediaItemId));
        $mediaItem->state = MediaItemState::Failed;
        $mediaItems->save($mediaItem);
    }
    $untouchedMediaItemIdA = (string) $itemsA[2]->mediaItemId;
    $untouchedStateBefore = $mediaItems->find(MediaItemId::parse($untouchedMediaItemIdA))->state;

    // A failed item in a different stash must never be retried by stash A's request.
    $mediaItemB = $mediaItems->find(MediaItemId::parse($mediaItemIdB));
    $mediaItemB->state = MediaItemState::Failed;
    $mediaItems->save($mediaItemB);

    $response = $this->http->post('/api/v1/stashes/' . $stashIdA . '/retry-failed', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    expect($response->body['created_count'])->toBe(2)
        ->and($response->body['jobs'])->toHaveCount(2);

    foreach ($failedMediaItemIdsA as $mediaItemId) {
        expect($mediaItems->find(MediaItemId::parse($mediaItemId))->state)->toBe(MediaItemState::DownloadPending);
    }

    $this->processAllJobs();

    foreach ($failedMediaItemIdsA as $mediaItemId) {
        expect($mediaItems->find(MediaItemId::parse($mediaItemId))->state)->toBe(MediaItemState::Ready);
    }

    expect($mediaItems->find(MediaItemId::parse($untouchedMediaItemIdA))->state)->toBe($untouchedStateBefore)
        ->and($mediaItems->find(MediaItemId::parse($mediaItemIdB))->state)->toBe(MediaItemState::Failed);
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

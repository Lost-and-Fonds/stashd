<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use Tempest\Database\Direction;
use Tempest\Http\Status;

test('stash.retry_failed retries every failed item in the stash, ignores non-failed items and other stashes', function (): void {
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

    // A failed item in a *different* stash must never be retried by stash A's command.
    $mediaItemB = $mediaItems->find(MediaItemId::parse($mediaItemIdB));
    $mediaItemB->state = MediaItemState::Failed;
    $mediaItems->save($mediaItemB);

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'stash.retry_failed',
        'options' => ['stash_id' => $stashIdA],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $worker = $this->container->get(\App\Jobs\JobWorkerService::class);
    expect($worker->processNextJob())->toBeTrue();

    foreach ($failedMediaItemIdsA as $mediaItemId) {
        expect($mediaItems->find(MediaItemId::parse($mediaItemId))->state)->toBe(MediaItemState::DownloadPending);
    }

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $response->body['command_id'], headers: $headers)->assertOk();
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['retried_count'])->toBe(2);

    foreach ($failedMediaItemIdsA as $mediaItemId) {
        expect($mediaItems->find(MediaItemId::parse($mediaItemId))->state)->toBe(MediaItemState::Ready);
    }

    expect($mediaItems->find(MediaItemId::parse($untouchedMediaItemIdA))->state)->toBe($untouchedStateBefore)
        ->and($mediaItems->find(MediaItemId::parse($mediaItemIdB))->state)->toBe(MediaItemState::Failed);
});

test('stash.retry_failed rejects an unknown stash id', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/commands', [
        'type' => 'stash.retry_failed',
        'options' => ['stash_id' => 'stash_does_not_exist'],
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);
});

test('stash.retry_failed with nothing to retry completes with retried_count 0', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('retry-all-none-failed');

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'stash.retry_failed',
        'options' => ['stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $response->body['command_id'], headers: $headers)->assertOk();
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['retried_count'])->toBe(0);
});

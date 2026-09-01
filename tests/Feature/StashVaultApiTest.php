<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Support\PrefixedUlid;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use Tempest\Http\Status;

test('GET /api/v1/stashes lists stashes', function (): void {
    [$headersA, $stashIdA] = $this->bootstrapFakeDownloadStash('phase6-stashes-a');
    [, $stashIdB] = $this->bootstrapFakeDownloadStash('phase6-stashes-b');

    $response = $this->http->get('/api/v1/stashes', headers: $headersA)
        ->assertStatus(Status::OK);

    $ids = array_column($response->body['stashes'], 'id');

    expect($ids)->toContain($stashIdA)
        ->and($ids)->toContain($stashIdB)
        ->and($response->body['stashes'][0])->toHaveKeys([
            'id',
            'name',
            'sync_mode',
            'download_policy',
            'organization_mode',
            'state',
            'created_at',
            'updated_at',
            'last_discovery_at',
        ]);
});

test('GET /api/v1/stashes/{id} returns stash detail', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('phase6-stash-detail');

    $this->http->get('/api/v1/stashes/' . $stashId, headers: $headers)
        ->assertStatus(Status::OK)
        ->assertJsonSubset(['stash' => ['id' => $stashId, 'state' => 'ready']]);
});

test('GET /api/v1/stashes/{id} returns 404 for an unknown stash', function (): void {
    [$headers] = $this->bootstrapFakeDownloadStash('phase6-stash-missing');

    $this->http->get('/api/v1/stashes/stash_01ARZ3NDEKTSV4RRFFQ69G5FAV', headers: $headers)
        ->assertStatus(Status::NOT_FOUND)
        ->assertJsonSubset(['error' => ['code' => 'not_found']]);
});

test('GET /api/v1/stashes/{id}/items lists stash items', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('phase6-stash-items');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $response = $this->http->get('/api/v1/stashes/' . $stashId . '/items', headers: $headers)
        ->assertStatus(Status::OK);

    $mediaItemIds = array_column($response->body['items'], 'media_item_id');
    $item = $response->body['items'][array_search($mediaItemId, $mediaItemIds, true)];

    expect($mediaItemIds)->toContain($mediaItemId)
        ->and($item)->toHaveKeys(['id', 'stash_id', 'media_item_id', 'state', 'position', 'media_item', 'total_asset_size_bytes'])
        ->and($item['media_item'])->toHaveKeys(['title', 'state', 'thumbnail_uri', 'duration_seconds', 'content_type', 'published_at', 'failure_reason'])
        ->and($item['total_asset_size_bytes'])->toBeGreaterThan(0);
});

test('a failed download surfaces its failure reason, and retrying clears it once it succeeds', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('retry-failure');

    $jobs = $this->container->get(JobRepository::class);
    $mediaItems = $this->container->get(MediaItemRepository::class);

    $failedJob = $jobs->create(
        intent: JobIntent::Download,
        entityType: 'media_item',
        entityId: PrefixedUlid::parse($mediaItemId),
    );
    $failedJob->state = JobState::Failed;
    $failedJob->lastError = 'download_failed: fake network error';
    $jobs->save($failedJob);

    $mediaItem = $mediaItems->find(MediaItemId::parse($mediaItemId));
    $mediaItem->state = MediaItemState::Failed;
    $mediaItems->save($mediaItem);

    $findItem = function () use ($headers, $stashId, $mediaItemId) {
        $response = $this->http->get('/api/v1/stashes/' . $stashId . '/items', headers: $headers)->assertOk();
        $mediaItemIds = array_column($response->body['items'], 'media_item_id');

        return $response->body['items'][array_search($mediaItemId, $mediaItemIds, true)];
    };

    $item = $findItem();
    expect($item['media_item']['state'])->toBe('failed')
        ->and($item['media_item']['failure_reason'])->toBe('download_failed: fake network error');

    // Retrying is exactly what the UI's retry button does: dispatch item.download again.
    // MediaItemState::Failed -> DownloadPending -> Downloading -> Ready is an allowed path.
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($mediaItems->find(MediaItemId::parse($mediaItemId))->state)->toBe(MediaItemState::DownloadPending);

    $this->processAllJobs();

    $item = $findItem();
    expect($item['media_item']['state'])->toBe('ready')
        ->and($item['media_item']['failure_reason'])->toBeNull();
});

test('GET /api/v1/stashes/{id}/items marks ignored items with their filter reason', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Ignored Items List'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/ignored-items-list'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->get('/api/v1/stashes/' . $stashId . '/items', headers: $headers)->assertStatus(Status::OK);

    $byState = [];

    foreach ($response->body['items'] as $item) {
        $byState[$item['state']][] = $item['ignored_reason'];
    }

    expect($byState['active'] ?? [])->toHaveCount(1)
        ->and($byState['ignored'] ?? [])->toHaveCount(2)
        ->and(array_unique($byState['ignored']))->toBe(['filter_title_regex']);
});

test('GET /api/v1/stashes/{id}/inputs lists stash inputs', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('phase6-stash-inputs');

    $response = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)
        ->assertStatus(Status::OK);

    expect($response->body['inputs'])->toHaveCount(1)
        ->and($response->body['inputs'][0])->toHaveKeys(['id', 'stash_id', 'provider_key', 'source_uri', 'state'])
        // stash_id is a StashId value object on the record -- must serialize
        // as a plain string in API output, not leak as {"value": ...}.
        ->and($response->body['inputs'][0]['stash_id'])->toBe($stashId);
});

test('GET /api/v1/items lists media items across stashes', function (): void {
    [$headersA, , $mediaItemIdA] = $this->bootstrapFakeDownloadStash('phase6-items-a');
    [, , $mediaItemIdB] = $this->bootstrapFakeDownloadStash('phase6-items-b');

    $response = $this->http->get('/api/v1/items', headers: $headersA)
        ->assertStatus(Status::OK);

    $ids = array_column($response->body['items'], 'id');

    expect($ids)->toContain($mediaItemIdA)
        ->and($ids)->toContain($mediaItemIdB)
        ->and($response->body['items'][0])->toHaveKeys(['id', 'provider_key', 'title', 'state']);
});

test('broadcast rebuild, verify, and prune commands dispatch end-to-end', function (): void {
    [$headers, , , $broadcastId] = $this->bootstrapFakeDownloadBroadcast('phase6-broadcast-actions');

    foreach (['broadcast.rebuild', 'broadcast.verify', 'broadcast.prune'] as $type) {
        $command = $this->http->post('/api/v1/commands', [
            'type' => $type,
            'options' => ['broadcast_id' => $broadcastId],
        ], headers: $headers)->assertStatus(Status::CREATED);

        $this->processAllJobs();

        $show = $this->http->get('/api/v1/commands/' . $command->body['command_id'], headers: $headers)
            ->assertStatus(Status::OK);

        expect($show->body['jobs'][0]['state'])->toBe('ready');
    }
});

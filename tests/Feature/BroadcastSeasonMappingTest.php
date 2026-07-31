<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use Tempest\Http\Status;

test('season mapping is rejected for non-series broadcast types', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Podcast Stash'], headers: $headers)->assertStatus(Status::CREATED);
    $broadcast = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'My Podcast',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->patch('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/season-mapping', [
        'mapping' => [],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('season mapping rejects a stash input id belonging to a different stash', function (): void {
    $headers = $this->authHeaders();

    $stashA = $this->http->post('/api/v1/stashes', ['name' => 'Stash A'], headers: $headers)->assertStatus(Status::CREATED);
    $stashB = $this->http->post('/api/v1/stashes', ['name' => 'Stash B'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/cross-stash-input'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $addToB = $this->http->post('/api/v1/stashes/' . $stashB->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $addToBCommand = $this->http->get('/api/v1/commands/' . $addToB->body['command_id'], headers: $headers)->assertOk();
    $stashBInputId = $addToBCommand->body['command']['result']['stash_input_id'];

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashA->body['stash']['id'] . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Series A',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->patch('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/season-mapping', [
        'mapping' => [$stashBInputId => 2],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('broadcast.rebuild honours per-input season mapping, falling back to season 01 for unmapped inputs', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Season Mapping Stash'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $addInput = function (string $sourceUri) use ($headers, $stashId): string {
        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => ['source_uri' => $sourceUri],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $add = $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $command = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers)->assertOk();

        return $command->body['command']['result']['stash_input_id'];
    };

    $inputA = $addInput('fake://channel/season-mapping-a');
    $inputB = $addInput('fake://channel/season-mapping-b');

    $broadcastResponse = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Season Mapping Broadcast',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcastResponse->body['broadcast']['id'];

    $patch = $this->http->patch('/api/v1/broadcasts/' . $broadcastId . '/season-mapping', [
        'mapping' => [$inputB => 3],
    ], headers: $headers);
    $patch->assertOk();
    expect($patch->body['broadcast']['settings']['season_mapping'])->toBe([$inputB => 3]);

    $expectedEpisodeNumbers = [];

    foreach (StashItemRecord::select()->where('stashInputId', $inputA)->all() as $stashItem) {
        $expectedEpisodeNumbers[(string) $stashItem->id] = $stashItem->position;
        $stashItem->position = 4 - $stashItem->position;
        $stashItem->save();
    }

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $rebuildCommand = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->assertOk();
    expect($rebuildCommand->body['command']['result']['publish']['published_count'])->toBe(6)
        ->and($rebuildCommand->body['command']['result']['prune']['removed_count'])->toBe(0);

    $itemsResponse = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)->assertOk();

    $stashItemIdsByInput = [
        $inputA => array_map(static fn ($item): string => (string) $item->id, StashItemRecord::select()->where('stashInputId', $inputA)->all()),
        $inputB => array_map(static fn ($item): string => (string) $item->id, StashItemRecord::select()->where('stashInputId', $inputB)->all()),
    ];

    $pathsForA = [];
    $pathsForB = [];

    foreach ($itemsResponse->body['items'] as $item) {
        if (in_array($item['stash_item_id'], $stashItemIdsByInput[$inputA], true)) {
            $pathsForA[] = $item['published_path'];
        } elseif (in_array($item['stash_item_id'], $stashItemIdsByInput[$inputB], true)) {
            $pathsForB[] = $item['published_path'];
        }
    }

    expect($pathsForA)->toHaveCount(3)
        ->and($pathsForB)->toHaveCount(3);

    foreach ($pathsForA as $path) {
        expect($path)->toContain('/Season 01/');
    }

    foreach ($pathsForB as $path) {
        expect($path)->toContain('/Season 03/');
    }

    foreach ($itemsResponse->body['items'] as $item) {
        $episodeNumber = $expectedEpisodeNumbers[$item['stash_item_id']] ?? null;

        if ($episodeNumber !== null) {
            expect($item['published_path'])->toContain(sprintf('S01E%03d', $episodeNumber));
        }
    }

    $patch = $this->http->patch('/api/v1/broadcasts/' . $broadcastId . '/season-mapping', [
        'mapping' => [$inputB => 4],
    ], headers: $headers)->assertOk();
    expect($patch->body['broadcast']['settings']['season_mapping'])->toBe([$inputB => 4]);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $rebuildCommand = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->assertOk();
    expect($rebuildCommand->body['command']['result']['prune']['removed_count'])->toBeGreaterThan(0);

    $itemsResponse = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)->assertOk();

    foreach ($itemsResponse->body['items'] as $item) {
        expect($item['published_path'])->not->toContain('/Season 03/');
    }

    expect(is_dir(dirname($pathsForB[0])))->toBeFalse();
});

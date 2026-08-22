<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\StashdConfig;
use Tempest\Http\Status;

test('a broadcast with destination_path publishes under {destination_path}/{name}, not the default root', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('destination-path-relocate');
    $config = $this->container->get(StashdConfig::class);
    $destinationPath = $config->mediaPath . '/external-test-' . bin2hex(random_bytes(3));

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'My External Show',
        'slug' => 'external-show-' . bin2hex(random_bytes(3)),
        'settings' => ['destination_path' => $destinationPath],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $expectedRoot = $destinationPath . '/My External Show';

    expect(is_dir($expectedRoot))->toBeTrue()
        ->and(is_file($expectedRoot . '/.stashd-broadcast'))->toBeTrue()
        ->and(trim((string) file_get_contents($expectedRoot . '/.stashd-broadcast')))->toBe($broadcastId)
        ->and(is_dir($config->broadcastsPath() . '/jellyfin/My External Show'))->toBeFalse();

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    expect($items->body['items'][0]['published_path'])->toStartWith($expectedRoot);
});

test('creating a broadcast with a destination_path that overlaps a protected storage root is rejected', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('destination-path-invalid-create'), 0, 2);
    $config = $this->container->get(StashdConfig::class);

    $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Bad Destination',
        'slug' => 'bad-destination-' . bin2hex(random_bytes(3)),
        'settings' => ['destination_path' => $config->vaultPath()],
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);
});

test('PATCH destination validates and updates, and clears back to the default root when set to null', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('destination-path-patch');
    $config = $this->container->get(StashdConfig::class);
    $destinationPath = $config->mediaPath . '/external-patch-test-' . bin2hex(random_bytes(3));

    $this->http->patch('/api/v1/broadcasts/' . $broadcastId . '/destination', [
        'destination_path' => 'relative/not/allowed',
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);

    $ok = $this->http->patch('/api/v1/broadcasts/' . $broadcastId . '/destination', [
        'destination_path' => $destinationPath,
    ], headers: $headers)->assertOk();
    expect($ok->body['broadcast']['settings']['destination_path'])->toBe($destinationPath);

    $cleared = $this->http->patch('/api/v1/broadcasts/' . $broadcastId . '/destination', [
        'destination_path' => null,
    ], headers: $headers)->assertOk();
    expect($cleared->body['broadcast']['settings']['destination_path'] ?? null)->toBeNull();
});

test('rebuild refuses to touch a pre-existing directory Stashd did not create', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('destination-path-foreign-dir');
    $config = $this->container->get(StashdConfig::class);
    $destinationPath = $config->mediaPath . '/external-foreign-' . bin2hex(random_bytes(3));

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Foreign Folder',
        'slug' => 'foreign-folder-' . bin2hex(random_bytes(3)),
        'settings' => ['destination_path' => $destinationPath],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];

    $foreignRoot = $destinationPath . '/Foreign Folder';
    mkdir($foreignRoot, 0775, true);
    file_put_contents($foreignRoot . '/not-stashds.txt', 'pre-existing content');

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);

    expect($command->body['command']['state'])->toBe('failed')
        ->and($command->body['jobs'][0]['last_error'])->toContain('broadcast_destination_conflict')
        ->and(is_file($foreignRoot . '/not-stashds.txt'))->toBeTrue()
        ->and(file_get_contents($foreignRoot . '/not-stashds.txt'))->toBe('pre-existing content');
});

test('two broadcasts of the same type and name collide on the default root with a clear error, not a silent clobber', function (): void {
    [$headersA, $stashIdA, $mediaItemIdA, , $connectionA] = $this->bootstrapJellyfinDownloadBroadcast('destination-path-collide-a');
    [$headersB, $stashIdB, $mediaItemIdB, , $connectionB] = $this->bootstrapJellyfinDownloadBroadcast('destination-path-collide-b');

    foreach ([[$headersA, $mediaItemIdA, $stashIdA], [$headersB, $mediaItemIdB, $stashIdB]] as [$headers, $mediaItemId, $stashId]) {
        $this->http->post('/api/v1/commands', [
            'type' => 'item.download',
            'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
        ], headers: $headers);
    }
    $this->processAllJobs();

    $broadcastA = $this->http->post('/api/v1/stashes/' . $stashIdA . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Duplicate Name Show',
        'slug' => 'dup-a-' . bin2hex(random_bytes(3)),
        'settings' => ['media_server_connection_id' => $connectionA],
    ], headers: $headersA)->assertStatus(Status::CREATED);

    $broadcastB = $this->http->post('/api/v1/stashes/' . $stashIdB . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Duplicate Name Show',
        'slug' => 'dup-b-' . bin2hex(random_bytes(3)),
        'settings' => ['media_server_connection_id' => $connectionB],
    ], headers: $headersB)->assertStatus(Status::CREATED);

    $rebuildA = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastA->body['broadcast']['id']],
    ], headers: $headersA)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    expect($this->http->get('/api/v1/commands/' . $rebuildA->body['command_id'], headers: $headersA)->body['command']['state'])
        ->toBe('completed');

    $rebuildB = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastB->body['broadcast']['id']],
    ], headers: $headersB)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $commandB = $this->http->get('/api/v1/commands/' . $rebuildB->body['command_id'], headers: $headersB);

    expect($commandB->body['command']['state'])->toBe('failed')
        ->and($commandB->body['jobs'][0]['last_error'])->toContain('broadcast_destination_conflict');

    $itemsA = $this->http->get('/api/v1/broadcasts/' . $broadcastA->body['broadcast']['id'] . '/items', headers: $headersA);
    expect($itemsA->body['items'][0]['state'])->toBe('ready');
});

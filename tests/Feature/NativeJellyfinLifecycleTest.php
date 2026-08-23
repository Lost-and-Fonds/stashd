<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastRecord;
use Tempest\Database\PrimaryKey;

test('Jellyfin native and Wasmtime implementations share the PostgreSQL Broadcast lifecycle', function (): void {
    if (getenv('STASHD_NATIVE_PLUGIN_TEST') !== '1') {
        $this->markTestSkipped('Native Jellyfin lifecycle test requires explicit runtime setup.');
    }

    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapJellyfinDownloadBroadcast('jellyfin-runtime-selection');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $before = BroadcastRecord::findById(new PrimaryKey($broadcastId));
    expect($before)->not->toBeNull();
    $identity = [$before->type, $before->name, $before->slug, $before->settings];

    foreach (['native', 'wasmtime', 'native'] as $runtime) {
        putenv('STASHD_BROADCAST_IMPLEMENTATIONS=' . json_encode(['jellyfin' => $runtime], JSON_THROW_ON_ERROR));
        $rebuild = $this->http->post('/api/v1/commands', [
            'type' => 'broadcast.rebuild',
            'options' => ['broadcast_id' => $broadcastId],
        ], headers: $headers);
        $this->processAllJobs();
        $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);

        $after = BroadcastRecord::findById(new PrimaryKey($broadcastId));
        expect($command->body['command']['state'])->toBe('completed')
            ->and([$after->type, $after->name, $after->slug, $after->settings])->toBe($identity)
            ->and($after->state->value)->toBe('ready');
    }

    $item = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)->body['items'][0];
    expect(is_file($item['published_path']))->toBeTrue();
});

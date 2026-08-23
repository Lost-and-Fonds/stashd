<?php

declare(strict_types=1);

use App\Broadcasts\BroadcastRecord;
use App\Stashes\StashId;
use App\Stashes\StashInputRepository;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('native and Wasmtime Plex implementations share the PostgreSQL Broadcast lifecycle', function (): void {
    if (getenv('STASHD_NATIVE_PLEX_PLUGIN_TEST') !== '1') {
        $this->markTestSkipped('Native Plex lifecycle test requires explicit runtime setup.');
    }

    [$headers, $stashId, $mediaItemId] = array_slice($this->bootstrapFakeDownloadStash('plex-runtime-selection'), 0, 3);
    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'plex',
        'name' => 'Runtime Plex',
        'endpoint' => 'https://plex.test',
        'token' => 'fixture-plex-token',
        'settings' => ['library_id' => '1', 'library_name' => 'TV Shows'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'plex',
        'name' => 'Plex Runtime Show',
        'slug' => 'plex-runtime-' . bin2hex(random_bytes(3)),
        'settings' => [
            'media_server_connection_id' => $server->body['connection']['id'],
            'captions' => 'creator_only',
            'caption_languages' => 'en',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $input = $this->container->get(StashInputRepository::class)->listForStash(StashId::parse($stashId))[0];
    $this->http->patch('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/source-settings', [
        'source_reference' => (string) $input->id,
        'settings' => ['season' => 3],
    ], headers: $headers)->assertOk();
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $before = BroadcastRecord::findById(new PrimaryKey($broadcast->body['broadcast']['id']));
    expect($before)->not->toBeNull();
    $identity = [$before->type, $before->name, $before->slug, $before->settings];

    foreach (['native', 'wasmtime', 'native'] as $runtime) {
        putenv('STASHD_BROADCAST_IMPLEMENTATIONS=' . json_encode(['plex' => $runtime], JSON_THROW_ON_ERROR));
        $rebuild = $this->http->post('/api/v1/commands', [
            'type' => 'broadcast.rebuild',
            'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
        ], headers: $headers);
        $this->processAllJobs();
        $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
        $after = BroadcastRecord::findById(new PrimaryKey($broadcast->body['broadcast']['id']));
        $item = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/items', headers: $headers)->body['items'][0];

        expect($command->body['command']['state'])->toBe('completed', json_encode($command->body, JSON_THROW_ON_ERROR))
            ->and([$after->type, $after->name, $after->slug, $after->settings])->toBe($identity)
            ->and($after->state->value)->toBe('ready')
            ->and(is_file($item['published_path']))->toBeTrue()
            ->and(is_file(dirname(dirname($item['published_path'])) . '/tvshow.nfo'))->toBeTrue();
    }
});

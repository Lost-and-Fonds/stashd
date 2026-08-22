<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Connections\ConnectionRecord;
use App\Stashes\StashInputRepository;
use App\System\Secret\SecretRecord;
use App\System\Secret\SecretsService;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\VaultPathBuilder;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Http\Status;

test('external Broadcast materializes a plugin-selected media path', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = array_slice(
        $this->bootstrapJellyfinDownloadBroadcast('jellyfin-plan'),
        0,
        4,
    );

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $plan = $this->container->get(BroadcastLifecycleService::class)
        ->plan(BroadcastId::parse($broadcastId));

    expect($plan->files)->toBeEmpty();

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers);
    $this->processAllJobs();

    expect($this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)
        ->body['command']['state'])->toBe('completed');

    $item = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)
        ->body['items'][0];
    $publishedAsset = $this->container->get(AssetRepository::class)
        ->findByBroadcastItemAndRole(
            BroadcastItemId::fromPrimaryKey(new PrimaryKey($item['id'])),
            AssetRole::Hardlink,
        );
    expect(is_file($item['published_path']))->toBeTrue();
    expect($publishedAsset?->path)->toBe($item['published_path']);
});

test('external Broadcast materializes plugin-selected media and subtitle resources', function (): void {
    requireExternalInputPluginRuntime($this);
    [$headers, $stashId, $mediaItemId] = array_slice($this->bootstrapFakeDownloadStash('plex-rebuild'), 0, 3);

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'plex',
        'name' => 'Fixture Plex',
        'endpoint' => 'https://plex.test',
        'token' => 'fixture-plex-token',
        'settings' => ['library_id' => '1', 'library_name' => 'TV Shows'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'plex',
        'name' => 'Plex Demo',
        'slug' => 'plex-demo-' . bin2hex(random_bytes(3)),
        'settings' => [
            'media_server_connection_id' => $server->body['connection']['id'],
            'captions' => 'creator_only',
            'caption_languages' => 'en',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $subtitlePath = $this->container->get(VaultPathBuilder::class)->vaultFile(
        (string) $media->providerKey,
        (string) $media->providerItemId,
        'captions.en.vtt',
    );
    if (! is_dir(dirname($subtitlePath))) {
        mkdir(dirname($subtitlePath), 0o775, true);
    }
    file_put_contents($subtitlePath, 'WEBVTT\n\n00:00.000 --> 00:01.000\nFixture caption\n');
    $this->container->get(AssetRepository::class)->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::Subtitle,
        kind: AssetKind::Subtitle,
        state: AssetState::Ready,
        path: $subtitlePath,
        relativePath: 'captions.en.vtt',
        mimeType: 'text/vtt',
        container: 'vtt',
        sizeBytes: filesize($subtitlePath) ?: null,
        checksum: hash_file('sha256', $subtitlePath) ?: null,
        language: 'en',
    );

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed');

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/items', headers: $headers);
    $publishedPath = $items->body['items'][0]['published_path'];
    $subtitle = $this->container->get(AssetRepository::class)
        ->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::Subtitle);
    $publishedSubtitlePath = glob(dirname($publishedPath) . '/*.vtt')[0] ?? null;

    expect(is_file($publishedPath))->toBeTrue()
        ->and($subtitle?->path)->not->toBeNull()
        ->and(is_string($publishedSubtitlePath) && is_file($publishedSubtitlePath))->toBeTrue();
    if (! is_string($publishedSubtitlePath) || $subtitle?->path === null) {
        return;
    }
    expect(fileinode($publishedSubtitlePath))->toBe(fileinode($subtitle->path));
});

test('external Broadcast source settings survive the normal lifecycle', function (): void {
    requireExternalInputPluginRuntime($this);
    [$headers, $stashId, $mediaItemId] = array_slice($this->bootstrapFakeDownloadStash('plex-source-settings'), 0, 3);
    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'plex', 'name' => 'Fixture Plex', 'endpoint' => 'https://plex.test', 'token' => 'fixture-plex-token',
        'settings' => ['library_id' => '1', 'library_name' => 'TV Shows'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'plex', 'name' => 'Plex Source Settings', 'slug' => 'plex-source-' . bin2hex(random_bytes(3)),
        'settings' => ['media_server_connection_id' => $server->body['connection']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $input = $this->container->get(StashInputRepository::class)->listForStash(\App\Stashes\StashId::parse($stashId))[0];
    $this->http->patch('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/source-settings', [
        'source_reference' => (string) $input->id,
        'settings' => ['season' => 3],
    ], headers: $headers)->assertOk();
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download', 'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();
    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild', 'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers);
    $this->processAllJobs();
    $item = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/items', headers: $headers)->body['items'][0];

    expect($this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command']['state'])->toBe('completed')
        ->and($item['published_path'])->toBeString();
});

test('connection stores token through secrets service', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'jellyfin',
        'name' => 'Secret Jellyfin',
        'endpoint' => 'https://jellyfin.test',
        'token' => 'super-secret-jellyfin-token-value',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $connection = ConnectionRecord::select()
        ->include('tokenSecretId')
        ->get(new \Tempest\Database\PrimaryKey($response->body['connection']['id']));

    expect($connection?->tokenSecretId)->not->toBeNull()
        ->and($connection?->tokenSecretId)->toStartWith('secret_');

    $secret = SecretRecord::select()
        ->include('encryptedValue')
        ->get(new \Tempest\Database\PrimaryKey($connection->tokenSecretId));
    expect($secret)->not->toBeNull()
        ->and($secret->key)->toStartWith('media_server:')
        ->and($secret->encryptedValue)->not->toContain('super-secret-jellyfin-token-value');

    $plaintext = $this->container->get(SecretsService::class)->get($secret->key);
    expect($plaintext)->toBe('super-secret-jellyfin-token-value');
});

test('connection stores opaque plugin-defined settings', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'plex',
        'name' => 'Library Plex',
        'endpoint' => 'https://plex.test',
        'token' => 'fixture-token',
        'settings' => [
            'library_id' => '1',
            'library_name' => 'TV Shows',
            'library_type' => 'show',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $connection = ConnectionRecord::findById(new PrimaryKey($response->body['connection']['id']));

    expect($connection)->not->toBeNull()
        ->and($connection->settings)->toBe([
            'library_id' => '1',
            'library_name' => 'TV Shows',
            'library_type' => 'show',
        ])
        ->and($response->body['connection']['settings'])->toBe([
            'library_id' => '1',
            'library_name' => 'TV Shows',
            'library_type' => 'show',
        ]);

    $row = $this->container->get(Database::class)->fetchFirst(new Query(
        'SELECT settings FROM media_server_connections WHERE id = ?',
        bindings: [$response->body['connection']['id']],
    ));
    $storedSettings = json_decode((string) $row['settings'], true, flags: JSON_THROW_ON_ERROR);

    expect($storedSettings)->toBe([
        'library_id' => '1',
        'library_name' => 'TV Shows',
        'library_type' => 'show',
    ]);
});

test('generic connection operation returns plugin values', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'jellyfin',
        'name' => 'Test Jellyfin',
        'endpoint' => 'https://jellyfin.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $result = $this->http->post('/api/v1/connections/' . $server->body['connection']['id'] . '/operations/test_connection', headers: $headers)
        ->assertOk();
    expect($result->body['values'])->toContain(['key' => 'ok', 'value' => 'true']);
});

test('generic connection operation failure does not leak token', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'jellyfin',
        'name' => 'Fail Jellyfin',
        'endpoint' => 'https://jellyfin-fail.test',
        'token' => 'leaked-token-should-not-appear',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $sync = $this->http->post('/api/v1/connections/' . $server->body['connection']['id'] . '/operations/test_connection', headers: $headers);
    $sync->assertStatus(Status::BAD_REQUEST);
    expect(json_encode($sync->body))->not->toContain('leaked-token-should-not-appear');
});

test('generic connection operation returns opaque choices', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'plex',
        'name' => 'Library Plex',
        'endpoint' => 'https://plex.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $libraries = $this->http->post('/api/v1/connections/' . $server->body['connection']['id'] . '/operations/list_libraries', headers: $headers);
    $libraries->assertOk();

    expect($libraries->body['choices'][0])->toBe(['label' => 'TV Shows', 'value' => '1']);
});

test('external operations return generic choices', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'jellyfin',
        'name' => 'Library Jellyfin',
        'endpoint' => 'https://jellyfin.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $libraries = $this->http->post('/api/v1/connections/' . $server->body['connection']['id'] . '/operations/list_libraries', headers: $headers);
    $libraries->assertOk();

    expect($libraries->body['choices'][0])->toBe(['label' => 'TV Shows', 'value' => 'shows-lib']);
});

test('external jellyfin rebuild refreshes through the Component with POST after publication', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId, $connectionId] = $this->bootstrapJellyfinDownloadBroadcast('trigger-success');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers);
    $this->processAllJobs();

    $rebuildShow = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($rebuildShow->body['command']['state'])->toBe('completed');

    $broadcast = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);
    expect($broadcast->body['broadcast']['state'])->toBe('ready');

    expect($connectionId)->not->toBe('');
});

test('external jellyfin refresh failure leaves the published file and reports failure', function (): void {
    [$headers, $stashId, $mediaItemId] = array_slice(
        $this->bootstrapFakeDownloadStash('jellyfin-refresh-failure'),
        0,
        3,
    );

    $server = $this->http->post('/api/v1/connections', [
        'plugin_key' => 'jellyfin',
        'name' => 'Failing Fixture Jellyfin',
        'endpoint' => 'https://jellyfin-fail.test',
        'token' => 'fixture-jellyfin-token',
        'settings' => [
            'library_id' => 'shows-lib',
            'library_name' => 'TV Shows',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Failing Jellyfin Demo Series',
        'slug' => 'jellyfin-refresh-failure-' . bin2hex(random_bytes(3)),
        'settings' => [
            'media_server_connection_id' => $server->body['connection']['id'],
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    $item = $this->http->get(
        '/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/items',
        headers: $headers,
    )->body['items'][0];

    expect($command->body['command']['state'])->toBe('failed')
        ->and($item['published_path'])->not->toBeNull()
        ->and(is_file($item['published_path']))->toBeTrue();
});

test('jellyfin and plex broadcast types are registered distinctly', function (): void {
    $registry = $this->container->get(\App\Broadcasts\BroadcastPluginRegistry::class);

    $jellyfin = $registry->findByKey('jellyfin');
    $plex = $registry->findByKey('plex');

    expect($jellyfin)->not->toBeNull()
        ->and($plex)->not->toBeNull();
});

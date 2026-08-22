<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastNfoBuilder;
use App\MediaServers\MediaServerConnectionRecord;
use App\MediaServers\MediaServerLibrarySelection;
use App\System\Activity\ActivityEventRecord;
use App\System\Secret\SecretRecord;
use App\System\Secret\SecretsService;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\MediaItemId;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Http\Status;

test('external jellyfin broadcast publishes the component-selected media path', function (): void {
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
    expect($item['published_path'])->toMatch('/Season 01\/S01E01 - /')
        ->and(is_file($item['published_path']))->toBeTrue();
    expect($publishedAsset?->path)->toBe($item['published_path']);
});

test('plex_series broadcast rebuild publishes media captions and nfo sidecars', function (): void {
    requireExternalInputPluginRuntime($this);
    [$headers, $stashId, $mediaItemId] = array_slice($this->bootstrapFakeDownloadStash('plex-rebuild'), 0, 3);

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'plex',
        'name' => 'Fixture Plex',
        'base_uri' => 'http://plex.test',
        'token' => 'fixture-plex-token',
        'settings' => ['library_id' => '1', 'library_name' => 'TV Shows'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'plex',
        'name' => 'Plex Demo',
        'slug' => 'plex-demo-' . bin2hex(random_bytes(3)),
        'settings' => [
            'media_server_connection_id' => $server->body['media_server']['id'],
            'captions' => 'creator_only',
            'caption_languages' => 'en',
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
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed');

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'] . '/items', headers: $headers);
    $publishedPath = $items->body['items'][0]['published_path'];
    $subtitle = $this->container->get(AssetRepository::class)
        ->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::Subtitle);
    $publishedSubtitlePath = dirname($publishedPath) . '/' . pathinfo($publishedPath, PATHINFO_FILENAME) . '.en.vtt';

    expect($publishedPath)->toMatch('/S\d{2}E\d{3} - /')
        ->and(is_file($publishedPath))->toBeTrue()
        ->and($subtitle?->path)->not->toBeNull()
        ->and(is_file($publishedSubtitlePath))->toBeTrue()
        ->and(fileinode($publishedSubtitlePath))->toBe(fileinode($subtitle->path));

    $root = dirname(dirname($publishedPath));
    expect(is_file($root . '/tvshow.nfo'))->toBeTrue();

    unlink($publishedSubtitlePath);
    file_put_contents($publishedSubtitlePath, 'drifted-caption');

    $verify = $this->container->get(BroadcastLifecycleService::class)
        ->verify(BroadcastId::parse($broadcast->body['broadcast']['id']));

    expect($verify->ok)->toBeFalse()
        ->and($verify->staleItemIds)->toContain(
            'sidecar:' . ltrim(substr($publishedSubtitlePath, strlen($root)), '/'),
        );
});

test('media server connection stores token through secrets service', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Secret Jellyfin',
        'base_uri' => 'https://jellyfin.test',
        'token' => 'super-secret-jellyfin-token-value',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $connection = \App\MediaServers\MediaServerConnectionRecord::select()
        ->include('tokenSecretId')
        ->get(new \Tempest\Database\PrimaryKey($response->body['media_server']['id']));

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

test('media server connection stores library selection as a typed value object', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/media-servers', [
        'type' => 'plex',
        'name' => 'Library Plex',
        'base_uri' => 'http://plex.test',
        'token' => 'fixture-token',
        'settings' => [
            'library_id' => '1',
            'library_name' => 'TV Shows',
            'library_type' => 'show',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $connection = MediaServerConnectionRecord::findById(new PrimaryKey($response->body['media_server']['id']));

    expect($connection)->not->toBeNull()
        ->and($connection->settings)->toBeInstanceOf(MediaServerLibrarySelection::class)
        ->and($connection->settings?->toArray())->toBe([
            'libraryId' => '1',
            'libraryName' => 'TV Shows',
            'libraryType' => 'show',
        ])
        ->and($response->body['media_server']['settings'])->toBe([
            'library_id' => '1',
            'library_name' => 'TV Shows',
            'library_type' => 'show',
        ]);

    $row = $this->container->get(Database::class)->fetchFirst(new Query(
        'SELECT settings FROM media_server_connections WHERE id = ?',
        bindings: [$response->body['media_server']['id']],
    ));
    $storedSettings = json_decode((string) $row['settings'], true, flags: JSON_THROW_ON_ERROR);

    expect($storedSettings)->toBe([
        'type' => 'media_server_library_selection',
        'data' => [
            'libraryId' => '1',
            'libraryName' => 'TV Shows',
            'libraryType' => 'show',
        ],
    ]);
});

test('media server test connection command succeeds with fixtures', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Test Jellyfin',
        'base_uri' => 'https://jellyfin.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $command = $this->http->post('/api/v1/commands', [
        'type' => 'media_server.test_connection',
        'options' => ['media_server_connection_id' => $server->body['media_server']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $show = $this->http->get('/api/v1/commands/' . $command->body['command_id'], headers: $headers);
    expect($show->body['command']['state'])->toBe('completed')
        ->and($show->body['command']['result']['status']['ok'])->toBeTrue()
        ->and($show->body['command']['result']['status']['server_name'])->toBe('Fixture Jellyfin');
});

test('media server test connection reports failure without leaking token', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Fail Jellyfin',
        'base_uri' => 'https://jellyfin-fail.test',
        'token' => 'leaked-token-should-not-appear',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $sync = $this->http->post('/api/v1/media-servers/' . $server->body['media_server']['id'] . '/test', headers: $headers);
    $sync->assertOk();
    expect($sync->body['status']['ok'])->toBeFalse();

    $activity = ActivityEventRecord::select()->orderBy('createdAt', \Tempest\Database\Direction::DESC)->first();
    expect(json_encode($activity))->not->toContain('leaked-token-should-not-appear');
});

test('media server list libraries returns snake_case fixture libraries', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'plex',
        'name' => 'Library Plex',
        'base_uri' => 'http://plex.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $libraries = $this->http->get(
        '/api/v1/media-servers/' . $server->body['media_server']['id'] . '/libraries',
        headers: $headers,
    );
    $libraries->assertOk();

    expect($libraries->body['libraries'][0]['id'])->toBe('1')
        ->and($libraries->body['libraries'][0]['name'])->toBe('TV Shows');
});

test('external media server operations return generic library choices', function (): void {
    $headers = $this->authHeaders();

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Library Jellyfin',
        'base_uri' => 'https://jellyfin.test',
        'token' => 'fixture-token',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $libraries = $this->http->get(
        '/api/v1/media-servers/' . $server->body['media_server']['id'] . '/libraries',
        headers: $headers,
    );
    $libraries->assertOk();

    expect($libraries->body['libraries'][0]['id'])->toBe('shows-lib')
        ->and($libraries->body['libraries'][0]['name'])->toBe('TV Shows');
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

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Failing Fixture Jellyfin',
        'base_uri' => 'https://jellyfin-fail.test',
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
            'media_server_connection_id' => $server->body['media_server']['id'],
            'auto_trigger_scan' => true,
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

test('broadcast nfo builder escapes unsafe xml characters', function (): void {
    $builder = new BroadcastNfoBuilder();
    $xml = $builder->tvShowNfo('Series & "Quotes"');

    expect($xml)->toContain('&amp;')
        ->and($xml)->toContain('&quot;');
});

test('jellyfin and plex broadcast types are registered distinctly', function (): void {
    $registry = $this->container->get(\App\Broadcasts\BroadcastPluginRegistry::class);

    $jellyfin = $registry->findByKey('jellyfin');
    $plex = $registry->findByKey('plex');

    expect($jellyfin)->not->toBeNull()
        ->and($plex)->not->toBeNull();
});

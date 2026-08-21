<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastNfoBuilder;
use App\Broadcasts\BroadcastTriggerRecord;
use App\Broadcasts\BroadcastTriggerRunRecord;
use App\Broadcasts\MediaServerScanTriggerSettings;
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

test('jellyfin_series broadcast plan includes SxxExxx filenames and nfo sidecars', function (): void {
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

    expect($plan->files[0]->filename)->toMatch('/^S\d{2}E\d{3} - /')
        ->and($plan->sidecars)->not->toBeEmpty();

    $hasTvShowNfo = false;

    foreach ($plan->sidecars as $sidecar) {
        if (str_ends_with($sidecar->relativePath, 'tvshow.nfo')) {
            $hasTvShowNfo = true;
            break;
        }
    }

    expect($hasTvShowNfo)->toBeTrue();
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
        'base_uri' => 'http://jellyfin.test',
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
        'base_uri' => 'http://jellyfin.test',
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
        'base_uri' => 'http://jellyfin-fail.test',
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

test('broadcast trigger scan success records trigger run without failing broadcast', function (): void {
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

    $runs = BroadcastTriggerRunRecord::select()->all();
    expect($runs)->not->toBeEmpty()
        ->and($runs[0]->state->value)->toBe('ready');

    $triggerRecord = BroadcastTriggerRecord::select()
        ->where('broadcastId', $broadcastId)
        ->first();

    expect($triggerRecord)->not->toBeNull()
        ->and($triggerRecord->settings)->toBeInstanceOf(MediaServerScanTriggerSettings::class)
        ->and($triggerRecord->settings?->mediaServerConnectionId)->toBe($connectionId);

    $row = $this->container->get(Database::class)->fetchFirst(new Query(
        'SELECT settings FROM broadcast_triggers WHERE id = ?',
        bindings: [(string) $triggerRecord->id],
    ));
    $storedSettings = json_decode((string) $row['settings'], true, flags: JSON_THROW_ON_ERROR);

    expect($storedSettings)->toBe([
        'type' => 'media_server_scan_trigger_settings',
        'data' => [
            'mediaServerConnectionId' => $connectionId,
        ],
    ]);

    $types = array_map(
        static fn (ActivityEventRecord $event): string => $event->type,
        ActivityEventRecord::select()->orderBy('createdAt', \Tempest\Database\Direction::DESC)->limit(15)->all(),
    );
    expect($types)->toContain('broadcast.trigger_succeeded');
});

test('broadcast trigger scan failure does not mark broadcast failed', function (): void {
    [$headers, $stashId, $mediaItemId] = array_slice($this->bootstrapFakeDownloadStash('trigger-fail'), 0, 3);

    $server = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Fail Trigger Jellyfin',
        'base_uri' => 'http://jellyfin-fail.test',
        'token' => 'fixture-token',
        'settings' => ['library_id' => 'shows-lib'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Trigger Fail Series',
        'slug' => 'trigger-fail-' . bin2hex(random_bytes(3)),
        'settings' => ['media_server_connection_id' => $server->body['media_server']['id']],
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

    expect($this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command']['state'])
        ->toBe('completed');

    $show = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'], headers: $headers);
    expect($show->body['broadcast']['state'])->toBe('ready');

    $trigger = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.trigger',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $triggerShow = $this->http->get('/api/v1/commands/' . $trigger->body['command_id'], headers: $headers);
    expect($triggerShow->body['command']['state'])->toBe('completed')
        ->and($triggerShow->body['command']['result']['trigger']['failure_count'])->toBeGreaterThan(0);

    $broadcastAfter = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'], headers: $headers);
    expect($broadcastAfter->body['broadcast']['state'])->toBe('ready');

    $run = BroadcastTriggerRunRecord::select()->orderBy('createdAt', \Tempest\Database\Direction::DESC)->first();
    expect($run?->state->value)->toBe('failed');
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

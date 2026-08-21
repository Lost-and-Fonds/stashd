<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRecord;
use App\Config\StashdConfig;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
use App\System\Activity\ActivityEventRecord;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemState;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('valid audio podcast episode token returns an X-Accel-Redirect to the Vault asset', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-audio');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'episode-audio-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Episode Audio',
        'slug' => 'episode-audio-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $enclosureUrl = podcastEpisodeEnclosureUrlFromFeed($feedXml);
    $parts = podcastEpisodeUrlParts($enclosureUrl);

    // No Authorization header: the path tokens are the only credential.
    $response = $this->http->get(
        '/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.' . $parts['ext'],
    );

    $response->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'audio/mpeg')
        ->assertHeaderContains('X-Accel-Redirect', '/vault' . podcastEpisodeExpectedAccelPath($mediaItemId, 'original.mp3'));

    expect($response->body)->toBeNull();

    $chapters = $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/chapters.json');
    $chapters->assertStatus(Status::OK)->assertHeaderContains('Content-Type', 'application/json; charset=utf-8');
    expect(json_decode($chapters->body, true, flags: JSON_THROW_ON_ERROR))->toBe(['version' => '1.2.0', 'chapters' => []]);
});

test('valid podcast item token returns its source thumbnail through Caddy', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-artwork');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset($config, $this->container->get(AssetRepository::class), $mediaItemId, AssetKind::Audio, 'original.mp3', 'audio/mpeg', 'audio');
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/podcast-episode-tests/' . $media->providerItemId . '/thumbnail.jpg';
    file_put_contents($path, 'jpeg');
    $this->container->get(AssetRepository::class)->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::SourceThumbnail,
        kind: AssetKind::Image,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'podcast-episode-tests/' . $media->providerItemId . '/thumbnail.jpg',
        mimeType: 'image/jpeg',
        sizeBytes: 4,
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', ['type' => 'podcast', 'name' => 'Artwork', 'slug' => 'artwork-' . bin2hex(random_bytes(3))], headers: $headers)->assertStatus(Status::CREATED);
    $this->http->post('/api/v1/commands', ['type' => 'broadcast.rebuild', 'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']]], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $xml = simplexml_load_string((string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id'])));
    $image = $xml->channel->item->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->image;
    $url = (string) $image->attributes()['href'];
    $parts = podcastEpisodeUrlParts((string) $xml->channel->item->enclosure['url']);
    expect($url)->toContain('/artwork');
    $response = $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/artwork');
    $response->assertStatus(Status::OK)->assertHeaderContains('Content-Type', 'image/jpeg')->assertHeaderContains('X-Accel-Redirect', podcastEpisodeExpectedAccelPath($mediaItemId, 'thumbnail.jpg'));
});

test('valid video podcast episode token returns an X-Accel-Redirect to the Vault asset', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-video');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mp4',
        'video/mp4',
        'episode-video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Episode Video',
        'slug' => 'episode-video-' . bin2hex(random_bytes(3)),
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $enclosureUrl = podcastEpisodeEnclosureUrlFromFeed($feedXml);
    $parts = podcastEpisodeUrlParts($enclosureUrl);

    $response = $this->http->get(
        '/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.' . $parts['ext'],
    );

    $response->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'video/mp4')
        ->assertHeaderContains('X-Accel-Redirect', '/vault' . podcastEpisodeExpectedAccelPath($mediaItemId, 'original.mp4'));

    expect($response->body)->toBeNull();
});

test('episode route requires path tokens, not a query parameter', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-path-token');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'path-token-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Path Token Episode',
        'slug' => 'episode-path-token-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $parts = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml));

    // Smuggling the real tokens through a query string against bogus path segments must not resolve.
    $this->http->get('/b/invalid/items/invalid/episode.mp3?broadcast=' . rawurlencode($parts['broadcastToken']) . '&item=' . rawurlencode($parts['itemToken']))
        ->assertStatus(Status::NOT_FOUND);

    // Path-token form resolves.
    $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.' . $parts['ext'])
        ->assertStatus(Status::OK);
});

test('an unknown broadcast token returns a non-revealing 404', function (): void {
    $response = $this->http->get('/b/' . rawurlencode('unknown-broadcast-token-000000000000') . '/items/' . rawurlencode('whatever-item-token') . '/episode.mp3');

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee('unknown-broadcast-token-000000000000')
        ->assertNotSee('whatever-item-token');
});

test('an unknown item token for a valid broadcast token returns a non-revealing 404', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-bad-item-token');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'bad-item-token-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Bad Item Token',
        'slug' => 'episode-bad-item-token-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $broadcastToken = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml))['broadcastToken'];

    $response = $this->http->get('/b/' . rawurlencode($broadcastToken) . '/items/' . rawurlencode('this-item-token-does-not-exist') . '/episode.mp3');

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee('this-item-token-does-not-exist');
});

test('an item token bound to a different broadcast does not resolve', function (): void {
    [$headersA, $stashIdA, $mediaItemIdA] = podcastEpisodeReadyStash($this, 'episode-cross-a');
    [$headersB, $stashIdB, $mediaItemIdB] = podcastEpisodeReadyStash($this, 'episode-cross-b');
    $config = $this->container->get(StashdConfig::class);
    $assets = $this->container->get(AssetRepository::class);

    podcastEpisodeCreateAsset($config, $assets, $mediaItemIdA, AssetKind::Audio, 'original.mp3', 'audio/mpeg', 'cross-a-bytes');
    podcastEpisodeCreateAsset($config, $assets, $mediaItemIdB, AssetKind::Audio, 'original.mp3', 'audio/mpeg', 'cross-b-bytes');

    $broadcastA = $this->http->post('/api/v1/stashes/' . $stashIdA . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Cross A',
        'slug' => 'episode-cross-a-' . bin2hex(random_bytes(3)),
    ], headers: $headersA)->assertStatus(Status::CREATED);
    $broadcastB = $this->http->post('/api/v1/stashes/' . $stashIdB . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Cross B',
        'slug' => 'episode-cross-b-' . bin2hex(random_bytes(3)),
    ], headers: $headersB)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastA->body['broadcast']['id']],
    ], headers: $headersA)->assertStatus(Status::CREATED);
    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastB->body['broadcast']['id']],
    ], headers: $headersB)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXmlA = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcastA->body['broadcast']['id']));
    $feedXmlB = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcastB->body['broadcast']['id']));
    $partsA = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXmlA));
    $partsB = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXmlB));

    // Each combination resolves on its own broadcast...
    $this->http->get('/b/' . rawurlencode($partsA['broadcastToken']) . '/items/' . rawurlencode($partsA['itemToken']) . '/episode.' . $partsA['ext'])
        ->assertStatus(Status::OK);
    $this->http->get('/b/' . rawurlencode($partsB['broadcastToken']) . '/items/' . rawurlencode($partsB['itemToken']) . '/episode.' . $partsB['ext'])
        ->assertStatus(Status::OK);

    // ...but swapping the item token across broadcasts must not resolve in either direction.
    $this->http->get('/b/' . rawurlencode($partsA['broadcastToken']) . '/items/' . rawurlencode($partsB['itemToken']) . '/episode.' . $partsB['ext'])
        ->assertStatus(Status::NOT_FOUND);
    $this->http->get('/b/' . rawurlencode($partsB['broadcastToken']) . '/items/' . rawurlencode($partsA['itemToken']) . '/episode.' . $partsA['ext'])
        ->assertStatus(Status::NOT_FOUND);
});

test('rotating the broadcast token invalidates old episode urls while the new token plus current item token works', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-rotate');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'rotate-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Rotate Episode',
        'slug' => 'episode-rotate-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcastId));
    $oldParts = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml));

    $this->http->get('/b/' . rawurlencode($oldParts['broadcastToken']) . '/items/' . rawurlencode($oldParts['itemToken']) . '/episode.' . $oldParts['ext'])
        ->assertStatus(Status::OK);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $show = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);
    $newBroadcastToken = podcastEpisodeUrlParts($show->body['broadcast']['feed_url'])['broadcastToken'];

    expect($newBroadcastToken)->not->toBe($oldParts['broadcastToken']);

    // Old broadcast token no longer resolves, even paired with the still-valid item token.
    $this->http->get('/b/' . rawurlencode($oldParts['broadcastToken']) . '/items/' . rawurlencode($oldParts['itemToken']) . '/episode.' . $oldParts['ext'])
        ->assertStatus(Status::NOT_FOUND);

    // New broadcast token plus the unchanged item token resolves.
    $this->http->get('/b/' . rawurlencode($newBroadcastToken) . '/items/' . rawurlencode($oldParts['itemToken']) . '/episode.' . $oldParts['ext'])
        ->assertStatus(Status::OK);
});

test('a token bound to a non-podcast broadcast does not serve media', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('episode-nonpodcast');
    unset($headers, $stashId, $mediaItemId);

    $token = 'non-podcast-episode-token-1111111111111';
    $this->container->get(SecretsService::class)->put(
        'test.episode_nonpodcast_feed',
        SecretType::BroadcastToken,
        $token,
    );
    $secret = $this->container->get(SecretRepository::class)->findByKey('test.episode_nonpodcast_feed');

    $broadcasts = $this->container->get(\App\Broadcasts\BroadcastRepository::class);
    $broadcast = $broadcasts->find(BroadcastId::parse($broadcastId));
    $broadcast->tokenSecretId = (string) $secret->id;
    $broadcasts->save($broadcast);

    $response = $this->http->get('/b/' . rawurlencode($token) . '/items/' . rawurlencode('whatever-item-token') . '/episode.mp4');

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee($token)
        ->assertNotSee('whatever-item-token');
});

test('a missing vault asset after rebuild returns a safe 404 without leaking paths', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-missing-asset');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'missing-asset-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Missing Asset',
        'slug' => 'episode-missing-asset-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $parts = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml));

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $assetPath = $config->vaultPath() . '/podcast-episode-tests/' . $media->providerItemId . '/original.mp3';
    unlink($assetPath);

    $response = $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.' . $parts['ext']);

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee($config->broadcastsPath())
        ->assertNotSee($config->vaultPath())
        ->assertNotSee($broadcast->body['broadcast']['id'])
        ->assertNotSee($parts['broadcastToken'])
        ->assertNotSee($parts['itemToken']);
});

test('a mismatched extension returns a non-revealing 404', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-bad-ext');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'bad-ext-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Bad Extension',
        'slug' => 'episode-bad-ext-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $parts = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml));
    expect($parts['ext'])->toBe('mp3');

    $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.mp4')
        ->assertStatus(Status::NOT_FOUND);

    // Correct extension still resolves.
    $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.mp3')
        ->assertStatus(Status::OK);
});

test('podcast rebuild and episode requests do not leak raw tokens into activity', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastEpisodeReadyStash($this, 'episode-activity-redaction');
    $config = $this->container->get(StashdConfig::class);
    podcastEpisodeCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'activity-redaction-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Activity Redaction',
        'slug' => 'episode-activity-redaction-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastEpisodeFeedPath($config, $broadcast->body['broadcast']['id']));
    $parts = podcastEpisodeUrlParts(podcastEpisodeEnclosureUrlFromFeed($feedXml));

    $this->http->get('/b/' . rawurlencode($parts['broadcastToken']) . '/items/' . rawurlencode($parts['itemToken']) . '/episode.' . $parts['ext'])
        ->assertStatus(Status::OK);

    $activity = json_encode(ActivityEventRecord::select()->all(), JSON_THROW_ON_ERROR);

    expect($activity)->not->toContain($parts['broadcastToken'])
        ->and($activity)->not->toContain($parts['itemToken']);
});

/** @return array{0: array{Authorization: string}, 1: string, 2: string} */
function podcastEpisodeReadyStash(\Tests\IntegrationTestCase $test, string $channel): array
{
    [$headers, $stashId, $mediaItemId] = $test->bootstrapFakeDownloadStash($channel);

    foreach (StashItemRecord::select()->where('stashId', $stashId)->all() as $stashItem) {
        if ((string) $stashItem->mediaItemId === $mediaItemId) {
            continue;
        }

        $stashItem->state = StashItemState::Hidden;
        $stashItem->save();
    }

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->state = MediaItemState::Ready;
    $media->description = $media->title;
    $media->save();

    return [$headers, $stashId, $mediaItemId];
}

function podcastEpisodeCreateAsset(
    StashdConfig $config,
    AssetRepository $assets,
    string $mediaItemId,
    AssetKind $kind,
    string $filename,
    string $mimeType,
    string $contents,
): void {
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/podcast-episode-tests/' . $media->providerItemId . '/' . $filename;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    file_put_contents($path, $contents);

    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: $kind,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'podcast-episode-tests/' . $media->providerItemId . '/' . $filename,
        mimeType: $mimeType,
        sizeBytes: strlen($contents),
    );
}

/**
 * Mirrors {@see \App\Broadcasts\PodcastEpisodeController}'s own path-relative-
 * to-Vault-root + per-segment-rawurlencode logic, so this asserts the exact
 * value Caddy's intercept block (`docker/Caddyfile`) expects to rewrite the
 * request to.
 */
function podcastEpisodeExpectedAccelPath(string $mediaItemId, string $filename): string
{
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $relative = 'podcast-episode-tests/' . $media->providerItemId . '/' . $filename;
    $segments = array_map(rawurlencode(...), explode('/', $relative));

    return '/' . implode('/', $segments);
}

function podcastEpisodeFeedPath(StashdConfig $config, string $broadcastId): string
{
    $broadcast = BroadcastRecord::select()->get(new PrimaryKey($broadcastId))
        ?? throw new \RuntimeException('Broadcast not found: ' . $broadcastId);

    return (new BroadcastPathBuilder($config))->broadcastFile($broadcast, 'feed.xml');
}

function podcastEpisodeEnclosureUrlFromFeed(string $feedXml): string
{
    $xml = simplexml_load_string($feedXml);
    expect($xml)->not->toBeFalse();

    return (string) $xml->channel->item->enclosure['url'];
}

/** @return array{broadcastToken: string, itemToken: string, ext: string} */
function podcastEpisodeUrlParts(string $url): array
{
    $path = parse_url($url, PHP_URL_PATH);
    $segments = explode('/', trim((string) $path, '/'));
    $last = $segments[4] ?? '';
    $ext = str_starts_with($last, 'episode.') ? substr($last, strlen('episode.')) : '';

    return [
        'broadcastToken' => $segments[1] ?? '',
        'itemToken' => $segments[3] ?? '',
        'ext' => $ext,
    ];
}

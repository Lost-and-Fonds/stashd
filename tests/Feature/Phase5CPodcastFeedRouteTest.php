<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastRepository;
use App\Config\StashdConfig;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
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

test('public feed route serves the generated audio podcast feed to unauthenticated clients', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastRouteReadyStash($this, 'route-audio-feed');
    $config = $this->container->get(StashdConfig::class);
    podcastRouteCreateAudioAsset($config, $this->container->get(AssetRepository::class), $mediaItemId);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Route Audio Feed',
        'slug' => 'route-audio-feed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];
    $feedToken = podcastRouteTokenFromUrl($broadcast->body['broadcast']['feed_url']);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    // No auth headers: the feed URL itself is the only credential.
    $response = $this->http->get('/b/' . rawurlencode($feedToken) . '/feed.xml');
    $diskFeed = (string) file_get_contents(podcastRouteFeedPath($config, $broadcastId));

    $response->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'application/rss+xml; charset=utf-8');

    expect((string) $response->body)->toBe($diskFeed)
        ->and((string) $response->body)->toContain('<rss')
        ->and((string) $response->body)->toContain('<channel>')
        ->and((string) $response->body)->toContain('<item>')
        ->and((string) $response->body)->toContain('Fake Episode 1')
        ->and((string) $response->body)->not->toContain($config->vaultPath());
});

test('public feed route requires the token in the path, not a query parameter', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastRouteReadyStash($this, 'route-path-token');
    $config = $this->container->get(StashdConfig::class);
    podcastRouteCreateAudioAsset($config, $this->container->get(AssetRepository::class), $mediaItemId);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Path Token Feed',
        'slug' => 'path-token-feed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $feedToken = podcastRouteTokenFromUrl($broadcast->body['broadcast']['feed_url']);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    // Same token smuggled through a query string against a bogus path must not resolve.
    $this->http->get('/b/invalid/feed.xml?token=' . rawurlencode($feedToken))
        ->assertStatus(Status::NOT_FOUND);

    // Path-token form resolves.
    $this->http->get('/b/' . rawurlencode($feedToken) . '/feed.xml')
        ->assertStatus(Status::OK);
});

test('public feed route returns a non-revealing 404 for an unknown token', function (): void {
    $response = $this->http->get('/b/' . rawurlencode('this-token-does-not-exist-000000000000') . '/feed.xml');

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee('<rss');
});

test('rotating the feed token invalidates the old url and the new url resolves', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastRouteReadyStash($this, 'route-rotate');
    $config = $this->container->get(StashdConfig::class);
    podcastRouteCreateAudioAsset($config, $this->container->get(AssetRepository::class), $mediaItemId);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Rotate Route Feed',
        'slug' => 'rotate-route-feed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];
    $oldToken = podcastRouteTokenFromUrl($broadcast->body['broadcast']['feed_url']);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->get('/b/' . rawurlencode($oldToken) . '/feed.xml')->assertStatus(Status::OK);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $show = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);
    $newToken = podcastRouteTokenFromUrl($show->body['broadcast']['feed_url']);

    expect($newToken)->not->toBe($oldToken);

    // Old (rotated/revoked) token no longer resolves; new token does.
    $this->http->get('/b/' . rawurlencode($oldToken) . '/feed.xml')->assertStatus(Status::NOT_FOUND);
    $this->http->get('/b/' . rawurlencode($newToken) . '/feed.xml')->assertStatus(Status::OK);
});

test('a feed token bound to a non-podcast broadcast does not return a feed', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('route-nonpodcast');
    unset($headers, $stashId, $mediaItemId);

    $token = 'non-podcast-feed-token-1111111111111111';
    $this->container->get(SecretsService::class)->put(
        'test.route_nonpodcast_feed',
        SecretType::BroadcastToken,
        $token,
    );
    $secret = $this->container->get(SecretRepository::class)->findByKey('test.route_nonpodcast_feed');

    $broadcasts = $this->container->get(BroadcastRepository::class);
    $broadcast = $broadcasts->find(BroadcastId::parse($broadcastId));
    $broadcast->tokenSecretId = (string) $secret->id;
    $broadcasts->save($broadcast);

    // Token decrypts to a real value, but the broadcast is jellyfin, not a podcast.
    $this->http->get('/b/' . rawurlencode($token) . '/feed.xml')->assertStatus(Status::NOT_FOUND);
});

test('a valid token with no generated feed returns a safe 404 without leaking paths', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastRouteReadyStash($this, 'route-missing-feed');
    $config = $this->container->get(StashdConfig::class);
    podcastRouteCreateAudioAsset($config, $this->container->get(AssetRepository::class), $mediaItemId);

    // Broadcast created (feed token issued) but never rebuilt, so feed.xml is absent.
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Missing Feed',
        'slug' => 'missing-feed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $feedToken = podcastRouteTokenFromUrl($broadcast->body['broadcast']['feed_url']);

    $response = $this->http->get('/b/' . rawurlencode($feedToken) . '/feed.xml');

    $response->assertStatus(Status::NOT_FOUND)
        ->assertNotSee($config->broadcastsPath())
        ->assertNotSee($config->vaultPath())
        ->assertNotSee($broadcast->body['broadcast']['id'])
        ->assertNotSee('<rss');
});

/** @return array{0: array{Authorization: string}, 1: string, 2: string} */
function podcastRouteReadyStash(\Tests\IntegrationTestCase $test, string $channel): array
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

function podcastRouteCreateAudioAsset(StashdConfig $config, AssetRepository $assets, string $mediaItemId): void
{
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/podcast-route-tests/' . $media->providerItemId . '/original.mp3';

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    file_put_contents($path, 'audio-bytes');

    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Audio,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'podcast-route-tests/' . $media->providerItemId . '/original.mp3',
        mimeType: 'audio/mpeg',
        sizeBytes: strlen('audio-bytes'),
    );
}

function podcastRouteFeedPath(StashdConfig $config, string $broadcastId): string
{
    $broadcast = BroadcastRecord::select()->get(new PrimaryKey($broadcastId))
        ?? throw new \RuntimeException('Broadcast not found: ' . $broadcastId);

    return (new BroadcastPathBuilder($config))->broadcastFile($broadcast, 'feed.xml');
}

function podcastRouteTokenFromUrl(string $feedUrl): string
{
    $path = parse_url($feedUrl, PHP_URL_PATH);
    $parts = explode('/', trim((string) $path, '/'));

    return $parts[1] ?? '';
}

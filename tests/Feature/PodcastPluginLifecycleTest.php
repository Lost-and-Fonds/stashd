<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\PublishedResourceRepository;
use App\Broadcasts\PublishedResourceService;
use App\Config\StashdConfig;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\VaultPathBuilder;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('Podcast plugin is discovered and publishes an audio feed from PostgreSQL lifecycle', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('podcast-lifecycle');
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $assets = $this->container->get(AssetRepository::class);
    $fakeOriginal = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::VaultOriginal);
    $fakeOriginal->state = AssetState::Stale;
    $assets->save($fakeOriginal);

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $audioPath = $this->container->get(VaultPathBuilder::class)->vaultFile(
        (string) $media->providerKey,
        (string) $media->providerItemId,
        'episode.mp4',
    );

    if (! is_dir(dirname($audioPath))) {
        mkdir(dirname($audioPath), 0o775, true);
    }
    $fixture = dirname(__DIR__, 2) . '/tests/fixtures/media/podcast-video-with-audio.mp4';
    file_put_contents($audioPath, file_get_contents($fixture));
    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $audioPath,
        relativePath: 'episode.mp4',
        mimeType: 'video/mp4',
        container: 'mp4',
        sizeBytes: filesize($audioPath) ?: null,
        checksum: hash_file('sha256', $audioPath) ?: null,
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Podcast Lifecycle',
        'slug' => 'podcast-lifecycle-' . bin2hex(random_bytes(3)),
        'settings' => [
            'title' => 'Lifecycle Podcast',
            'description' => 'Published from the application lifecycle.',
            'author' => 'Stashd',
            'media_kind' => 'audio',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed');

    $record = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcast->body['broadcast']['id']));
    $feedPath = $this->container->get(BroadcastPathBuilder::class)->broadcastFile($record, 'feed.xml');
    $feedXml = simplexml_load_file($feedPath);
    $enclosureUrl = (string) $feedXml->channel->item->enclosure['url'];
    expect(is_file($feedPath))->toBeTrue()
        ->and((string) $feedXml->channel->title)->toBe('Lifecycle Podcast')
        ->and($enclosureUrl)->toContain('/published/');

    $publications = $this->container->get(PublishedResourceRepository::class)->listForBroadcast(BroadcastId::parse($broadcast->body['broadcast']['id']));
    $publicationService = $this->container->get(PublishedResourceService::class);
    $feed = array_values(array_filter($publications, static fn($resource): bool => $resource->relativePath === 'feed.xml'))[0] ?? null;
    $enclosurePath = (string) parse_url($enclosureUrl, PHP_URL_PATH);
    $pathParts = explode('/', trim($enclosurePath, '/'));
    $publishedIndex = array_search('published', $pathParts, true);
    $enclosureId = is_int($publishedIndex) ? ($pathParts[$publishedIndex + 1] ?? null) : null;
    $audio = array_values(array_filter(
        $publications,
        static fn($resource): bool => (string) $resource->id === $enclosureId,
    ))[0] ?? null;
    expect($feed)->not->toBeNull()
        ->and($audio)->not->toBeNull();

    $feedCredential = $publicationService->credential($feed);
    $audioCredential = $publicationService->credential($audio);
    $this->http->get('/published/' . $feed->id . '/access/' . rawurlencode((string) $feedCredential))
        ->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'application/rss+xml');
    $audioSource = $publicationService->source($audio);
    $mediaRoot = rtrim($this->container->get(StashdConfig::class)->mediaPath, '/') . '/';
    $this->http->get('/published/' . $audio->id . '/access/' . rawurlencode((string) $audioCredential))
        ->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Length', (string) $audioSource['size'])
        ->assertHeaderContains('Accept-Ranges', 'bytes')
        ->assertHeaderContains('X-Accel-Redirect', '/' . ltrim(substr($audioSource['path'], strlen($mediaRoot)), '/'));
});

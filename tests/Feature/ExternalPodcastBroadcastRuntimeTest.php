<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\PublishedResourceRecord;
use App\Config\StashdConfig;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('normal broadcast lifecycle invokes the registered external Component', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('external-podcast-runtime');
    foreach (StashItemRecord::select()->where('stashId', $stashId)->all() as $stashItem) {
        if ((string) $stashItem->mediaItemId !== $mediaItemId) {
            $stashItem->state = StashItemState::Hidden;
            $stashItem->save();
        }
    }
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->state = \App\Vault\MediaItemState::Ready;
    $media->save();
    $config = $this->container->get(StashdConfig::class);
    $assets = $this->container->get(AssetRepository::class);
    externalPodcastRuntimeAsset($config, $assets, $mediaItemId);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'External Podcast',
        'slug' => 'external-podcast-' . bin2hex(random_bytes(3)),
        'settings' => ['title' => 'External Component Feed'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command'];
    $feedPath = (new BroadcastPathBuilder($config))->broadcastFile(
        BroadcastRecord::select()->get(new PrimaryKey($broadcast->body['broadcast']['id'])),
        'feed.xml',
    );
    $feed = (string) file_get_contents($feedPath);
    $xml = simplexml_load_string($feed);
    expect($command['result']['verify']['ok'])->toBeTrue()
        ->and((string) $xml->channel->title)->toBe('External Component Feed')
        ->and($feed)->toContain('/published/')
        ->and($feed)->not->toContain($config->vaultPath());

    $publications = PublishedResourceRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->all();
    expect($publications)->not->toBeEmpty();

    $second = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    expect($this->http->get('/api/v1/commands/' . $second->body['command_id'], headers: $headers)->body['command']['result']['verify']['ok'])->toBeTrue()
        ->and(PublishedResourceRecord::select()->where('broadcastId', $broadcast->body['broadcast']['id'])->all())->toHaveCount(count($publications));
});

test('external Podcast derives and reuses audio from a video Asset', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('external-podcast-video-runtime');
    foreach (StashItemRecord::select()->where('stashId', $stashId)->all() as $stashItem) {
        if ((string) $stashItem->mediaItemId !== $mediaItemId) {
            $stashItem->state = StashItemState::Hidden;
            $stashItem->save();
        }
    }
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->state = \App\Vault\MediaItemState::Ready;
    $media->save();
    $config = $this->container->get(StashdConfig::class);
    $assets = $this->container->get(AssetRepository::class);
    externalPodcastVideoRuntimeAsset($config, $assets, $mediaItemId);

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'External Podcast Video',
        'slug' => 'external-podcast-video-' . bin2hex(random_bytes(3)),
        'settings' => ['title' => 'External Video Feed', 'media_kind' => 'audio'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command'];
    $broadcastRecord = BroadcastRecord::select()->get(new PrimaryKey($broadcast->body['broadcast']['id']));
    $feedPath = (new BroadcastPathBuilder($config))->broadcastFile($broadcastRecord, 'feed.xml');
    $feed = (string) file_get_contents($feedPath);
    $xml = simplexml_load_string($feed);
    $source = AssetRecord::select()
        ->where('mediaItemId', $mediaItemId)
        ->where('role', AssetRole::VaultOriginal)
        ->where('kind', AssetKind::Video)
        ->first();
    $derived = AssetRecord::select()
        ->where('mediaItemId', $mediaItemId)
        ->where('role', AssetRole::Derived)
        ->where('kind', AssetKind::Audio)
        ->where('derivationKey', 'podcast-audio-v1')
        ->first();

    expect($command['result']['verify']['ok'])->toBeTrue()
        ->and($derived)->toBeInstanceOf(AssetRecord::class)
        ->and($derived->state)->toBe(AssetState::Ready)
        ->and((string) $derived->derivedFromAssetId)->toBe((string) $source->id)
        ->and($derived->checksum)->toMatch('/^[a-f0-9]{64}$/')
        ->and($derived->sizeBytes)->toBeGreaterThan(0)
        ->and($derived->mimeType)->toBe('audio/mpeg')
        ->and(is_file((string) $derived->path))->toBeTrue()
        ->and(str_starts_with((string) $derived->path, rtrim($config->vaultPath(), '/') . '/'))->toBeTrue()
        ->and((string) $xml->channel->item->enclosure['url'])->toContain('/published/')
        ->and((string) $xml->channel->item->enclosure['url'])->not->toContain($config->vaultPath());

    $publications = PublishedResourceRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->where('assetId', (string) $derived->id)
        ->all();
    expect($publications)->toHaveCount(1);
    expect((string) $publications[0]->assetId)->toBe((string) $derived->id);

    $second = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id'],],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    expect(AssetRecord::select()
        ->where('mediaItemId', $mediaItemId)
        ->where('role', AssetRole::Derived)
        ->where('derivationKey', 'podcast-audio-v1')
        ->all())->toHaveCount(1)
        ->and(PublishedResourceRecord::select()
            ->where('broadcastId', $broadcast->body['broadcast']['id'])
            ->where('assetId', (string) $derived->id)
            ->all())->toHaveCount(1)
        ->and($this->http->get('/api/v1/commands/' . $second->body['command_id'], headers: $headers)->body['command']['result']['verify']['ok'])->toBeTrue();
});

test('failed Podcast helper does not promote a derived Asset', function (): void {
    $component = getenv('STASHD_BROADCAST_PLUGIN_COMPONENT');
    $helper = is_string($component) ? dirname($component) . '/podcast/helpers/ffmpeg' : '';
    if ($helper === '' || ! is_file($helper)) {
        $this->markTestSkipped('The packaged Podcast helper is unavailable.');
    }

    $backup = $helper . '.test-backup';
    rename($helper, $backup);
    copy(__DIR__ . '/../fixtures/podcast/failing-ffmpeg.sh', $helper);
    chmod($helper, 0755);

    try {
        [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('external-podcast-helper-failure');
        foreach (StashItemRecord::select()->where('stashId', $stashId)->all() as $stashItem) {
            if ((string) $stashItem->mediaItemId !== $mediaItemId) {
                $stashItem->state = StashItemState::Hidden;
                $stashItem->save();
            }
        }
        $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
        $media->state = \App\Vault\MediaItemState::Ready;
        $media->save();
        $config = $this->container->get(StashdConfig::class);
        $assets = $this->container->get(AssetRepository::class);
        externalPodcastVideoRuntimeAsset($config, $assets, $mediaItemId);

        $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
            'type' => 'podcast',
            'name' => 'External Podcast Failure',
            'slug' => 'external-podcast-failure-' . bin2hex(random_bytes(3)),
            'settings' => ['media_kind' => 'audio'],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $rebuild = $this->http->post('/api/v1/commands', [
            'type' => 'broadcast.rebuild',
            'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command'];
        expect($command['state'])->toBe('failed')
            ->and(AssetRecord::select()
                ->where('mediaItemId', $mediaItemId)
                ->where('role', AssetRole::Derived)
                ->all())->toBeEmpty();
    } finally {
        @unlink($helper);
        rename($backup, $helper);
    }
});

function externalPodcastRuntimeAsset(StashdConfig $config, AssetRepository $assets, string $mediaItemId): void
{
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/external-podcast-tests/' . $media->providerItemId . '/episode.mp3';
    mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'external-podcast-bytes');
    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Audio,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'external-podcast-tests/' . $media->providerItemId . '/episode.mp3',
        mimeType: 'audio/mpeg',
        sizeBytes: 22,
    );
}

function externalPodcastVideoRuntimeAsset(StashdConfig $config, AssetRepository $assets, string $mediaItemId): void
{
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/external-podcast-tests/' . $media->providerItemId . '/video-with-audio.mp4';
    mkdir(dirname($path), 0775, true);
    copy(__DIR__ . '/../fixtures/podcast/video-with-audio.mp4', $path);
    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'external-podcast-tests/' . $media->providerItemId . '/video-with-audio.mp4',
        mimeType: 'video/mp4',
        sizeBytes: filesize($path) ?: 0,
    );
}

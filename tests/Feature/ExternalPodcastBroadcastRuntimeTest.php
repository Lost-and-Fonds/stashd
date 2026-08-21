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

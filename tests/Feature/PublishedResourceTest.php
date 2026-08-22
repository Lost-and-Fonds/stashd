<?php

declare(strict_types=1);

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\PublishedResourceController;
use App\Broadcasts\PublishedResourceService;
use App\Support\PrefixedUlid;
use App\System\Secret\SecretRepository;
use App\Vault\AssetRepository;
use Tempest\Http\Status;

test('publishes an existing asset through the neutral serving endpoint', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('published-asset');
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $broadcastId = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Published Asset',
    ], headers: $headers)->assertStatus(Status::CREATED)->body['broadcast']['id'];

    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $asset = $this->container->get(AssetRepository::class)->readyVaultOriginalsByMediaItem([$mediaItemId])[$mediaItemId];
    $resource = $this->container->get(PublishedResourceService::class)->publishAsset(
        $broadcast,
        $asset,
        'video/mp4',
        downloadName: 'asset.mp4',
    );

    $this->http->get('/published/' . $resource->id, headers: $headers)
        ->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'video/mp4')
        ->assertHeaderContains('Content-Length', (string) $asset->sizeBytes)
        ->assertHeaderContains('Accept-Ranges', 'bytes')
        ->assertHeaderContains('X-Accel-Redirect', '/vault/fake/items/published-asset-episode-1/original.fake');

    $this->http->get('/published/' . $resource->id, headers: $headers + ['Range' => 'bytes=0-3'])
        ->assertStatus(Status::OK)
        ->assertHeaderContains('Accept-Ranges', 'bytes');
});

test('publishes a generated file with protected access and rotates its credential', function (): void {
    [$headers, , , $broadcastId] = $this->bootstrapFakeDownloadBroadcast('published-generated');

    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $root = $this->container->get(BroadcastPathBuilder::class)->claimRoot($broadcast);
    file_put_contents($root . '/index.json', '{"ok":true}');

    $publications = $this->container->get(PublishedResourceService::class);
    $resource = $publications->publishFile(
        $broadcast,
        'index.json',
        'application/json',
        access: 'credential',
        downloadName: 'index.json',
    );
    expect($publications->source($resource)['size'])->toBe(11);
    $credential = $publications->credential($resource);

    $this->http->get('/published/' . $resource->id, headers: $headers)->assertStatus(Status::NOT_FOUND);
    $this->http->get('/published/' . $resource->id . '/access/wrong', headers: $headers)
        ->assertStatus(Status::NOT_FOUND);
    $protected = $this->container->get(PublishedResourceController::class)
        ->serveProtected((string) $resource->id, (string) $credential);
    expect($protected->getHeader('Content-Type')?->values)->toContain('application/json');
    expect($protected->getHeader('Content-Disposition')?->values)->toContain('inline; filename="index.json"');
    $this->http->get('/published/' . $resource->id . '/access/' . rawurlencode((string) $credential), headers: $headers)
        ->assertStatus(Status::OK)
        ->assertHeaderContains('Content-Type', 'application/json');

    $rotated = $publications->rotateCredential($resource);
    expect($rotated)->not->toBe($credential);

    $this->http->get('/published/' . $resource->id . '/access/' . rawurlencode((string) $credential), headers: $headers)
        ->assertStatus(Status::NOT_FOUND);
    $this->http->get('/published/' . $resource->id . '/access/' . rawurlencode($rotated), headers: $headers)
        ->assertStatus(Status::OK);
});

test('rejects unsafe generated publication paths', function (): void {
    [, , , $broadcastId] = $this->bootstrapFakeDownloadBroadcast('published-path');
    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));

    expect(fn() => $this->container->get(PublishedResourceService::class)->publishFile(
        $broadcast,
        '../outside.json',
        'application/json',
    ))->toThrow(RuntimeException::class);
});

test('deleting a broadcast revokes its publication credential', function (): void {
    [$headers, , , $broadcastId] = $this->bootstrapFakeDownloadBroadcast('published-delete');
    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $publications = $this->container->get(PublishedResourceService::class);
    $resource = $publications->publishFile($broadcast, 'feed.xml', 'application/xml', access: 'credential');
    $credential = $publications->credential($resource);

    $this->http->delete('/api/v1/broadcasts/' . $broadcastId, headers: $headers)->assertStatus(Status::ACCEPTED);
    $this->processAllJobs();

    $this->http->get('/published/' . $resource->id . '/access/' . rawurlencode((string) $credential), headers: $headers)
        ->assertStatus(Status::NOT_FOUND);

    $secret = $this->container->get(SecretRepository::class)->find(
        PrefixedUlid::parse((string) $resource->credentialSecretId),
    );
    expect($secret?->revokedAt)->not->toBeNull();
});

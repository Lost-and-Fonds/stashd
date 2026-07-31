<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastChapterRemuxer;
use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastFilenameBuilder;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastLifecycleService;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\HardlinkPublisher;
use App\Broadcasts\SponsorBlockRefreshRepository;
use App\Broadcasts\SponsorBlockSegment;
use App\Config\StashdConfig;
use App\Stashes\StashId;
use App\Stashes\StashItemRecord;
use App\System\Activity\ActivityEventRecord;
use App\System\Scheduler\SponsorBlockRefreshScheduler;
use App\Timeline\SponsorBlockTimelineSynchronizer;
use App\Timeline\TimelineEntryCategory;
use App\Timeline\TimelineEntryKind;
use App\Timeline\TimelineEntryRepository;
use App\Timeline\TimelineEntrySource;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use Tempest\Http\Status;

test('create jellyfin broadcast for stash returns snake_case json', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-create'), 0, 2);

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Demo Series',
        'slug' => 'demo-series',
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($response->body)->toHaveKey('broadcast')
        ->and($response->body)->toHaveKey('build_command_id')
        ->and($response->body['broadcast']['type'])->toBe('jellyfin')
        ->and($response->body['broadcast']['state'])->toBe('pending')
        ->and($response->body['broadcast']['stash_id'])->toBe($stashId)
        ->and($response->body['broadcast']['last_planned_at'])->toBeNull();

    $command = $this->http->get('/api/v1/commands/' . $response->body['build_command_id'], headers: $headers)->assertOk();
    expect($command->body['command']['type'])->toBe('broadcast.rebuild')
        ->and($command->body['jobs'][0]['state'])->toBe('pending');
});

test('an initial podcast build waits cleanly when stash media is not ready', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-waits-for-media'), 0, 2);

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $broadcast = $this->http->get('/api/v1/broadcasts/' . $response->body['broadcast']['id'], headers: $headers)
        ->assertOk()
        ->body['broadcast'];

    expect($broadcast['state'])->toBe('ready')
        ->and($broadcast['last_error'])->toBeNull()
        ->and($broadcast['items'])->toBeEmpty()
        ->and($broadcast['impact']['eligible_item_count'])->toBe(0)
        ->and($broadcast['impact']['skipped_item_count'])->toBeGreaterThan(0);
});

test('a broadcast created before input discovery rebuilds after the new media downloads', function (): void {
    $headers = $this->authHeaders();
    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Still Discovering',
        'download_policy' => 'video',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcastId = $broadcast->body['broadcast']['id'];
    $this->processAllJobs();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/still-discovering'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $result = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers)->assertOk()->body['broadcast'];
    expect($result['state'])->toBe('ready')
        ->and($result['last_error'])->toBeNull()
        ->and($result['items'])->toHaveCount(3)
        ->and(array_unique(array_column($result['items'], 'state')))->toBe(['ready']);
});

test('previewing a jellyfin broadcast reports eligible items and their vault size, with nothing needing transcode', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('broadcast-preview-jellyfin');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertOk();

    $preview = $response->body['preview'];

    expect($preview['eligible_item_count'])->toBe(1)
        ->and($preview['skipped_item_count'])->toBeGreaterThan(0)
        ->and($preview['vault_size_bytes'])->toBeGreaterThan(0)
        ->and($preview['hardlinked_item_count'])->toBe(1)
        ->and($preview['transcode_item_count'])->toBe(0);
});

test('previewing SponsorBlock chapters reports potential derived media storage', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('broadcast-preview-sponsorblock');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $preview = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'jellyfin',
        'sponsorblock_enabled' => true,
    ], headers: $headers)->assertOk()->body['preview'];

    expect($preview['derived_media_item_count'])->toBe(1)
        ->and($preview['derived_media_bytes'])->toBeGreaterThan(0);
});

test('previewing an audio podcast counts video-sourced episodes as needing transcode', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('broadcast-preview-podcast');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'podcast',
        'mediaKind' => 'audio',
    ], headers: $headers)->assertOk();

    $preview = $response->body['preview'];

    // manual_download-policy fake downloads produce a video original, so an
    // audio podcast needs it transcoded.
    expect($preview['eligible_item_count'])->toBe(1)
        ->and($preview['transcode_item_count'])->toBe(1)
        ->and($preview['hardlinked_item_count'])->toBe(0);
});

test('previewing skips items that have not been downloaded yet', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-preview-skipped'), 0, 2);

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertOk();

    $preview = $response->body['preview'];

    expect($preview['eligible_item_count'])->toBe(0)
        ->and($preview['skipped_item_count'])->toBeGreaterThan(0)
        ->and($preview['vault_size_bytes'])->toBe(0);
});

test('ready vault original batch lookup excludes empty, non-ready, and pathless assets', function (): void {
    $media = $this->container->get(MediaItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);
    $ready = $media->create('fake', 'batch-ready', 'fake://item/batch-ready', 'Ready', MediaItemState::Ready);
    $pending = $media->create('fake', 'batch-pending', 'fake://item/batch-pending', 'Pending', MediaItemState::Ready);
    $pathless = $media->create('fake', 'batch-pathless', 'fake://item/batch-pathless', 'Pathless', MediaItemState::Ready);

    $assets->create(
        MediaItemId::parse((string) $ready->id),
        AssetRole::VaultOriginal,
        AssetKind::Video,
        AssetState::Ready,
        path: '/vault/batch-ready.mp4',
    );
    $assets->create(
        MediaItemId::parse((string) $pending->id),
        AssetRole::VaultOriginal,
        AssetKind::Video,
        AssetState::Pending,
        path: '/vault/batch-pending.mp4',
    );
    $assets->create(
        MediaItemId::parse((string) $pathless->id),
        AssetRole::VaultOriginal,
        AssetKind::Video,
        AssetState::Ready,
    );

    expect($assets->readyVaultOriginalsByMediaItem([]))->toBeEmpty()
        ->and($assets->readyVaultOriginalsByMediaItem([
            (string) $ready->id,
            (string) $pending->id,
            (string) $pathless->id,
        ]))->toHaveKey((string) $ready->id)
        ->not->toHaveKey((string) $pending->id)
        ->not->toHaveKey((string) $pathless->id);
});

test('previewing does not create a broadcast record', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-preview-no-create'), 0, 2);

    $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertOk();

    $broadcasts = $this->http->get('/api/v1/stashes/' . $stashId . '/broadcasts', headers: $headers)->assertOk();

    expect($broadcasts->body['broadcasts'])->toBeEmpty();
});

test('previewing an unsupported broadcast type is rejected', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-preview-bad-type'), 0, 2);

    $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts/preview', [
        'type' => 'not_a_real_type',
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);
});

test('creating a broadcast without a name defaults to "{stash} {plugin label}"', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'My Channel',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($response->body['broadcast']['name'])->toBe('My Channel Jellyfin Series')
        ->and($response->body['broadcast']['slug'])->toBe('my-channel-jellyfin-series');
});

test('a second unnamed broadcast of the same type gets a deduped slug instead of erroring', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'My Channel',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $second = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($second->body['broadcast']['name'])->toBe('My Channel Jellyfin Series')
        ->and($second->body['broadcast']['slug'])->toBe('my-channel-jellyfin-series-2');
});

test('a podcast broadcast can be created with no name and no settings at all', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-defaults'), 0, 2);

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($response->body['broadcast']['name'])->toContain('Podcast');
});

test('broadcast.plan produces intended files without writing to disk', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-plan');

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->container->get(\App\Jobs\JobWorkerService::class)->processNextJob();

    expect($this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers)->body['command']['state'])
        ->toBe('completed');

    $config = $this->container->get(StashdConfig::class);
    $planner = $this->container->get(BroadcastLifecycleService::class);
    $plan = $planner->plan(BroadcastId::parse($broadcastId));

    expect($plan->files)->toHaveCount(1)
        ->and($plan->files[0]->absolutePath)->toStartWith($config->broadcastsPath())
        ->and(is_file($plan->files[0]->absolutePath))->toBeFalse();

    $command = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.plan',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $show = $this->http->get('/api/v1/commands/' . $command->body['command_id'], headers: $headers);
    expect($show->body['command']['state'])->toBe('completed')
        ->and($show->body['command']['result']['plan']['file_count'])->toBe(1);
});

test('broadcast.rebuild publishes hardlinks from vault originals', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-rebuild');

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $config = $this->container->get(StashdConfig::class);
    $media = MediaItemRecord::findById(new \Tempest\Database\PrimaryKey($mediaItemId));
    $vaultPath = $config->vaultPath() . '/fake/items/' . $media->providerItemId . '/original.fake';

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['publish']['published_count'])->toBe(1)
        ->and($command->body['command']['result']['verify']['ok'])->toBeTrue();

    $broadcast = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);
    expect($broadcast->body['broadcast']['state'])->toBe('ready');

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    expect($items->body['items'][0]['state'])->toBe('ready');

    $publishedPath = $items->body['items'][0]['published_path'];
    expect(is_file($publishedPath))->toBeTrue()
        ->and(is_file($vaultPath))->toBeTrue();

    if (HardlinkPublisher::sameFile($vaultPath, $publishedPath)) {
        expect(HardlinkPublisher::sameFile($vaultPath, $publishedPath))->toBeTrue();
    } else {
        expect(filesize($vaultPath))->toBe(filesize($publishedPath));
        expect(file_get_contents($vaultPath))->toBe(file_get_contents($publishedPath));
    }
});

test('broadcast publication schedules one delayed SponsorBlock refresh per item', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-sponsorblock-refresh');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $mediaItem = MediaItemRecord::findById(new \Tempest\Database\PrimaryKey($mediaItemId));
    $mediaItem->providerKey = 'youtube';
    $mediaItem->save();
    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $broadcast->settings = ['sponsorblock_enabled' => true, 'sponsorblock_categories' => ['sponsor']];
    $this->container->get(BroadcastRepository::class)->save($broadcast);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $itemId = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)
        ->assertOk()
        ->body['items'][0]['id'];
    $refreshes = $this->container->get(SponsorBlockRefreshRepository::class);

    $refresh = $refreshes->findForBroadcastItem(\App\Broadcasts\BroadcastItemId::parse($itemId));
    expect($refresh)->not->toBeNull()
        ->and($refresh->nextCheckAt->equals($refresh->createdAt))->toBeTrue()
        ->and($refresh->expiresAt->equals($refresh->createdAt->plusDays(7)))->toBeTrue();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    expect($refreshes->listDue(\Tempest\DateTime\DateTime::now(\Tempest\DateTime\Timezone::UTC)))->toHaveCount(1);

    expect($this->container->get(SponsorBlockRefreshScheduler::class)->scheduleDueRefresh())->toBeTrue()
        ->and(\App\Jobs\JobRecord::select()->where('intent', \App\Jobs\JobIntent::SponsorBlockRefresh)->first())->not->toBeNull();
});

test('SponsorBlock synchronization preserves provider chapters and ignores unchanged segments', function (): void {
    [, , $mediaItemId] = $this->bootstrapFakeDownloadBroadcast('broadcast-sponsorblock-timeline');
    $mediaItem = MediaItemId::parse($mediaItemId);
    $entries = $this->container->get(TimelineEntryRepository::class);
    $entries->create($mediaItem, TimelineEntrySource::Provider, TimelineEntryKind::Chapter, TimelineEntryCategory::Chapter, 0.0, 60.0, 'Provider chapter');
    $segments = [new SponsorBlockSegment('segment-1', TimelineEntryCategory::Sponsor, 15.0, 30.0, 'Ad read', ['UUID' => 'segment-1'])];
    $synchronizer = $this->container->get(SponsorBlockTimelineSynchronizer::class);

    expect($synchronizer->sync($mediaItem, $segments))->toBeTrue()
        ->and($synchronizer->sync($mediaItem, $segments))->toBeFalse()
        ->and($entries->listForMediaItem($mediaItem))->toHaveCount(2)
        ->and($entries->listForMediaItem($mediaItem)[0]->source)->toBe(TimelineEntrySource::Provider)
        ->and($entries->listForMediaItem($mediaItem)[1]->source)->toBe(TimelineEntrySource::SponsorBlock);
});

test('SponsorBlock chapters produce a broadcast-local remux without changing the Vault asset', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-sponsorblock-remux');
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $broadcast->settings = ['sponsorblock_enabled' => true, 'sponsorblock_categories' => ['sponsor']];
    $this->container->get(BroadcastRepository::class)->save($broadcast);
    $mediaItem = MediaItemId::parse($mediaItemId);
    $assets = $this->container->get(AssetRepository::class);
    $vault = $assets->readyVaultOriginalsByMediaItem([$mediaItemId])[$mediaItemId];
    $mp4Path = $vault->path . '.mp4';
    rename($vault->path, $mp4Path);
    $vault->path = $mp4Path;
    $assets->save($vault);
    $this->container->get(SponsorBlockTimelineSynchronizer::class)->sync(
        $mediaItem,
        [new SponsorBlockSegment('segment-1', TimelineEntryCategory::Sponsor, 15.0, 30.0, 'Ad read', ['UUID' => 'segment-1'])],
    );

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $publishedPath = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)
        ->assertOk()
        ->body['items'][0]['published_path'];
    $vaultPath = $assets->readyVaultOriginalsByMediaItem([$mediaItemId])[$mediaItemId]->path;

    expect(HardlinkPublisher::sameFile($vaultPath, $publishedPath))->toBeFalse()
        ->and(file_get_contents($publishedPath))->toContain('stub-ffmpeg-remux')
        ->and(file_get_contents($vaultPath))->not->toContain('stub-ffmpeg-remux');
});

test('chapter remux rejects a broadcast container that cannot carry chapters', function (): void {
    [, , $mediaItemId] = $this->bootstrapFakeDownloadBroadcast('broadcast-chapters-unsupported');
    $mediaItem = MediaItemId::parse($mediaItemId);
    $this->container->get(SponsorBlockTimelineSynchronizer::class)->sync(
        $mediaItem,
        [new SponsorBlockSegment('segment-1', TimelineEntryCategory::Sponsor, 15.0, 30.0, 'Ad read', ['UUID' => 'segment-1'])],
    );

    try {
        $this->container->get(BroadcastChapterRemuxer::class)->remux(
            $mediaItem,
            '/not-used/source.mp4',
            $this->container->get(StashdConfig::class)->broadcastsPath() . '/unsupported.avi',
        );
    } catch (BroadcastException $exception) {
        expect($exception->errorCode)->toBe('broadcast_chapters_unsupported')
            ->and($exception->getMessage())->toBe('The broadcast container cannot carry chapters.');

        return;
    }

    throw new \RuntimeException('Expected unsupported chapter container to be rejected.');
});

test('SponsorBlock polling ignores unsupported provider items', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-sponsorblock-unsupported');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $broadcast->settings = ['sponsorblock_enabled' => true, 'sponsorblock_categories' => ['sponsor']];
    $this->container->get(BroadcastRepository::class)->save($broadcast);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    expect($this->container->get(SponsorBlockRefreshRepository::class)
        ->listDue(\Tempest\DateTime\DateTime::now(\Tempest\DateTime\Timezone::UTC)))->toBeEmpty();
});

test('broadcast.rebuild_item republishes only the selected broadcast item', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-rebuild-item');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $item = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers)
        ->assertOk()
        ->body['items'][0];
    unlink($item['published_path']);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild_item',
        'options' => ['broadcast_item_id' => $item['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)
        ->assertOk()
        ->body['command'];

    expect($command['state'])->toBe('completed')
        ->and($command['target_type'])->toBe('broadcast_item')
        ->and($command['target_id'])->toBe($item['id'])
        ->and($command['result']['plan']['file_count'])->toBe(1)
        ->and($command['result']['verify']['ok'])->toBeTrue()
        ->and(is_file($item['published_path']))->toBeTrue();
});

test('broadcast rebuild reports truthful lifecycle phases', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-progress-phases');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $phases = [];
    $this->container->get(BroadcastLifecycleService::class)->rebuild(
        BroadcastId::parse($broadcastId),
        static function (string $phase) use (&$phases): void {
            $phases[] = $phase;
        },
    );

    expect($phases)->toBe([
        'Planning broadcast rebuild',
        'Publishing broadcast',
        'Verifying broadcast',
    ]);
});

test('a broadcast\'s show/index response embeds live items and impact, not just the create-time preview', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-impact');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $broadcast = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers)->assertOk()->body['broadcast'];

    expect($broadcast['items'])->toHaveCount(1)
        ->and($broadcast['items'][0]['state'])->toBe('ready')
        ->and($broadcast['impact']['eligible_item_count'])->toBe(1)
        ->and($broadcast['impact']['vault_size_bytes'])->toBeGreaterThan(0)
        ->and($broadcast['impact']['hardlinked_item_count'])->toBe(1)
        ->and($broadcast['impact']['transcode_item_count'])->toBe(0);

    $index = $this->http->get('/api/v1/stashes/' . $stashId . '/broadcasts', headers: $headers)->assertOk()->body['broadcasts'];
    expect($index[0]['impact'])->toBe($broadcast['impact']);
});

test('broadcast.verify marks missing generated files stale', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-verify-missing');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers);
    $this->processAllJobs();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers);
    $this->processAllJobs();

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    unlink($items->body['items'][0]['published_path']);

    $verify = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.verify',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $verify->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['verify']['ok'])->toBeFalse()
        ->and($command->body['command']['result']['verify']['missing_item_ids'])->not->toBeEmpty();

    $itemsAfter = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    expect($itemsAfter->body['items'][0]['state'])->toBe('stale');

    $broadcast = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);
    expect($broadcast->body['broadcast']['state'])->toBe('stale');
});

test('broadcast.prune removes generated files only and never vault originals', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-prune');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers);
    $this->processAllJobs();

    $config = $this->container->get(StashdConfig::class);
    $media = MediaItemRecord::findById(new \Tempest\Database\PrimaryKey($mediaItemId));
    $vaultPath = $config->vaultPath() . '/fake/items/' . $media->providerItemId . '/original.fake';

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers);
    $this->processAllJobs();

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    $publishedPath = $items->body['items'][0]['published_path'];
    expect(is_file($publishedPath))->toBeTrue();

    $broadcast = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcastId));
    $root = $this->container->get(BroadcastPathBuilder::class)->broadcastRoot($broadcast);
    file_put_contents($root . '/stale-extra.txt', 'stale');

    $prune = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.prune',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $prune->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['prune']['removed_count'])->toBeGreaterThan(0)
        ->and(is_file($root . '/stale-extra.txt'))->toBeFalse()
        ->and(is_file($root . '/.stashd-broadcast'))->toBeTrue()
        ->and(is_file($vaultPath))->toBeTrue()
        ->and(is_file($publishedPath))->toBeTrue();
});

test('hardlink unavailable returns stable broadcast_hardlink_unavailable error', function (): void {
    putenv('STASHD_BROADCAST_HARDLINK_FORCE_FAIL=1');
    $_ENV['STASHD_BROADCAST_HARDLINK_FORCE_FAIL'] = '1';

    try {
        [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-hardlink-fail');

        $this->http->post('/api/v1/commands', [
            'type' => 'item.download',
            'options' => [
                'media_item_id' => $mediaItemId,
                'stash_id' => $stashId,
            ],
        ], headers: $headers);
        $this->processAllJobs();

        $rebuild = $this->http->post('/api/v1/commands', [
            'type' => 'broadcast.rebuild',
            'options' => ['broadcast_id' => $broadcastId],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
        expect($command->body['command']['state'])->toBe('failed')
            ->and($command->body['jobs'][0]['last_error'])->toContain('broadcast_hardlink_unavailable');
    } finally {
        putenv('STASHD_BROADCAST_HARDLINK_FORCE_FAIL');
        unset($_ENV['STASHD_BROADCAST_HARDLINK_FORCE_FAIL']);
    }
});

test('broadcast rebuild is idempotent', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-idempotent');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers);
    $this->processAllJobs();

    foreach ([1, 2] as $attempt) {
        $rebuild = $this->http->post('/api/v1/commands', [
            'type' => 'broadcast.rebuild',
            'options' => ['broadcast_id' => $broadcastId],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
        expect($command->body['command']['state'])->toBe('completed');
    }

    $items = $this->http->get('/api/v1/broadcasts/' . $broadcastId . '/items', headers: $headers);
    expect($items->body['items'][0]['state'])->toBe('ready');
});

test('broadcast commands emit activity events', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-activity');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers);
    $this->processAllJobs();

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $types = array_map(
        static fn (ActivityEventRecord $event): string => $event->type,
        ActivityEventRecord::select()->orderBy('createdAt', \Tempest\Database\Direction::DESC)->limit(20)->all(),
    );

    expect($types)->toContain('broadcast.rebuild_started')
        ->and($types)->toContain('broadcast.published')
        ->and($types)->toContain('broadcast.verified');
});

test('broadcast filename builder rejects path traversal segments', function (): void {
    $builder = new BroadcastFilenameBuilder();
    $stashItem = new StashItemRecord(
        stashId: StashId::parse('stash_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        mediaItemId: MediaItemId::parse('media_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        state: \App\Stashes\StashItemState::Active,
        displayTitle: '../../etc/passwd',
    );
    $mediaItem = new MediaItemRecord(
        providerKey: 'fake',
        providerItemId: 'item-1',
        canonicalUri: 'fake://item/1',
        title: '../secret',
        state: \App\Vault\MediaItemState::Ready,
        upstreamState: \App\Vault\UpstreamState::Available,
    );

    $filename = $builder->episodeFilename($stashItem, $mediaItem, '/vault/original.fake', 1);

    expect($filename)->not->toContain('..')
        ->and($filename)->not->toContain('/');
});

test('broadcast hardlink assets are recorded with derived vault source', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('broadcast-assets');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers);
    $this->processAllJobs();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers);
    $this->processAllJobs();

    $hardlink = \App\Vault\AssetRecord::select()
        ->where('broadcastId', $broadcastId)
        ->where('role', AssetRole::Hardlink)
        ->first();

    expect($hardlink)->not->toBeNull()
        ->and($hardlink->derivedFromAssetId)->not->toBeNull()
        ->and($hardlink->state->value)->toBe('ready');
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastPluginRegistry;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\Plugins\PodcastBroadcastPlugin;
use App\Config\StashdConfig;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Jobs\JobState;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
use App\System\Activity\ActivityEventRecord;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Http\Status;

test('the podcast broadcast format is registered', function (): void {
    $registry = $this->container->get(BroadcastPluginRegistry::class);
    $plugin = $registry->findByKey('podcast');

    expect($plugin)->not->toBeNull()
        ->and($plugin->plugin)->toBeInstanceOf(PodcastBroadcastPlugin::class);
});

test('broadcast.rebuild writes deterministic audio podcast feed with tokenized enclosures', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-audio-feed');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Audio Feed',
        'slug' => 'audio-feed-' . bin2hex(random_bytes(3)),
        'settings' => [
            'title' => 'Audio & Feed',
            'description' => 'Private <audio> feed',
            'funding_url' => 'https://example.test/support',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $feedUrl = $broadcast->body['broadcast']['feed_url'];
    $feedToken = podcastFeedTokenFromUrl($feedUrl);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command'];
    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));
    $xml = simplexml_load_string($feedXml);
    expect($xml)->not->toBeFalse();
    $podcast = $xml->channel->children('https://podcastindex.org/namespace/1.0');

    $item = BroadcastItemRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->first();
    $itemToken = podcastFeedTokenFromEpisodeUrl((string) $xml->channel->item->enclosure['url']);

    expect($command['result']['publish']['published_count'])->toBe(1)
        ->and($command['result']['verify']['ok'])->toBeTrue()
        ->and((string) $xml->channel->title)->toBe('Audio & Feed')
        ->and((string) $xml->channel->description)->toBe('Private <audio> feed')
        ->and((string) $podcast->guid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and((string) $xml->channel->item->title)->toBe('Fake Episode 1')
        ->and((string) $xml->channel->item->description)->toBe('Fake episode 1 description.')
        ->and((string) $xml->channel->item->guid)->toBe('stashd:broadcast:' . $broadcast->body['broadcast']['id'] . ':item:' . (string) $item->id)
        ->and((string) $xml->channel->item->enclosure['url'])->toContain('/b/' . rawurlencode($feedToken) . '/items/')
        ->and((string) $xml->channel->item->enclosure['url'])->toContain('/episode.mp3')
        ->and((string) $xml->channel->item->enclosure['url'])->not->toContain('?')
        ->and((int) $xml->channel->item->enclosure['length'])->toBe(strlen('audio-bytes'))
        ->and((string) $xml->channel->item->enclosure['type'])->toBe('audio/mpeg')
        ->and((string) $xml->channel->item->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->duration)->toBe('630')
        ->and((string) $item->tokenPreview)->not->toContain($itemToken)
        ->and($feedXml)->not->toContain($config->vaultPath());

    $secondRebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $secondCommand = $this->http->get('/api/v1/commands/' . $secondRebuild->body['command_id'], headers: $headers)->body['command'];
    $secondFeedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));

    expect($secondCommand['result']['verify']['ok'])->toBeTrue()
        ->and($secondFeedXml)->toBe($feedXml)
        ->and($secondFeedXml)->not->toContain((string) $item->tokenPreview);

    $guid = (string) $podcast->guid;
    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $rotated = simplexml_load_string((string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id'])));
    expect((string) $rotated->channel->children('https://podcastindex.org/namespace/1.0')->guid)->toBe($guid);
});

test('broadcast.rebuild writes video podcast feed for supported ready video asset', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-video-feed');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mp4',
        'video/mp4',
        'video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Video Feed',
        'slug' => 'video-feed-' . bin2hex(random_bytes(3)),
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));
    $xml = simplexml_load_string($feedXml);

    expect($xml)->not->toBeFalse()
        ->and((string) $xml->channel->item->enclosure['url'])->toContain('/episode.mp4')
        ->and((string) $xml->channel->item->enclosure['type'])->toBe('video/mp4')
        ->and((string) $xml->channel->item->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->duration)->toBe('630');
});

test('caption downloads complete before they queue a podcast rebuild', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-captions-complete');
    podcastFeedCreateAsset(
        $this->container->get(StashdConfig::class),
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Captioned Feed',
        'slug' => 'captioned-feed-' . bin2hex(random_bytes(3)),
        'settings' => ['captions' => 'creator_only'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $captionJob = JobRecord::select()->where('intent', JobIntent::DownloadCaptions)->first();

    expect($captionJob?->state)->toBe(JobState::Ready);
});

test('audio podcast triggers a transcode fallback instead of failing for video only assets', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-audio-missing');
    podcastFeedCreateAsset(
        $this->container->get(StashdConfig::class),
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mp4',
        'video/mp4',
        'video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Audio Missing',
        'slug' => 'audio-missing-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);

    // Process only the rebuild job itself -- processAllJobs() drains the
    // whole queue including jobs created mid-loop, which would race straight
    // through the transcode job and the rebuild it auto-retriggers on
    // success, leaving nothing to observe at the intermediate "pending" step.
    $worker = $this->container->get(\App\Jobs\JobWorkerService::class);
    $worker->processNextJob();

    $item = BroadcastItemRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->first();

    // The episode is excluded and a transcode queued rather than the
    // broadcast failing outright -- this is the audio-podcast-on-a-
    // video-only-stash fallback this feature exists for, not a regression
    // of the old immediate-failure behaviour (still exercised by the
    // video-podcast "unsuitable asset" test below, which has no fallback
    // pathway).
    expect($item->lastError)->toBe('podcast_audio_transcode_pending')
        // publish() lands a pending transcode in Processing (not Failed), so
        // verify() running right after in the same rebuild can still move it
        // to Stale -- Failed's only allowed transition is back to
        // Processing, so landing here first would leave it stuck as Failed.
        ->and($item->state)->toBe(\App\Broadcasts\BroadcastItemState::Stale);

    // Draining the rest of the queue runs the transcode job and the
    // automatically re-triggered rebuild it queues on success, which picks
    // up the now-ready generated audio asset and publishes the episode --
    // no manual second rebuild needed.
    $this->processAllJobs();

    $reloadedItem = BroadcastItemRecord::findById(new PrimaryKey((string) $item->id));
    $audioAsset = $this->container->get(AssetRepository::class)
        ->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);

    expect($reloadedItem?->lastError)->toBeNull()
        ->and($reloadedItem?->state)->toBe(\App\Broadcasts\BroadcastItemState::Ready)
        ->and($audioAsset)->not->toBeNull()
        ->and($audioAsset->state)->toBe(AssetState::Ready)
        ->and($audioAsset->derivedFromAssetId)->not->toBeNull();
});

test('a failed transcode surfaces on the broadcast item without a manual rebuild', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-audio-transcode-failure');
    podcastFeedCreateAsset(
        $this->container->get(StashdConfig::class),
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mp4',
        'video/mp4',
        'video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Audio Transcode Failure',
        'slug' => 'audio-transcode-failure-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $gateway = $this->container->get(\App\Transcoding\Ffmpeg\FfmpegGateway::class);
    assert($gateway instanceof \App\Transcoding\Ffmpeg\StubFfmpegGateway);
    $gateway->failNextTranscode = true;

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);

    // One drain of the whole queue: rebuild queues the transcode, the
    // transcode fails, and the handler's failure-path retrigger (the fix
    // under test) queues a second rebuild that picks up the now-Failed
    // audio asset -- no manual rebuild needed to surface the real state.
    $this->processAllJobs();

    $item = BroadcastItemRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->first();
    $audioAsset = $this->container->get(AssetRepository::class)
        ->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);

    expect($audioAsset?->state)->toBe(AssetState::Failed)
        ->and($item->lastError)->toBe('podcast_audio_transcode_failed');
});

test('a failed transcode is not retried immediately, but is retried once the cooldown elapses', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-audio-transcode-retry');
    podcastFeedCreateAsset(
        $this->container->get(StashdConfig::class),
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mp4',
        'video/mp4',
        'video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Audio Transcode Retry',
        'slug' => 'audio-transcode-retry-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $gateway = $this->container->get(\App\Transcoding\Ffmpeg\FfmpegGateway::class);
    assert($gateway instanceof \App\Transcoding\Ffmpeg\StubFfmpegGateway);
    $gateway->failNextTranscode = true;
    // transcodeCalls is a shared counter across every test in this file
    // (the stub isn't reset per test) -- assert on the delta, not the
    // absolute count.
    $callsBefore = $gateway->transcodeCalls;

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $assets = $this->container->get(AssetRepository::class);
    $audioAsset = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);
    expect($audioAsset?->state)->toBe(AssetState::Failed)
        ->and($gateway->transcodeCalls - $callsBefore)->toBe(1);

    // Rebuilding again right away must not retry -- without the cooldown,
    // this would spin a broken transcode in a tight loop.
    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $stillFailed = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);
    expect($stillFailed?->state)->toBe(AssetState::Failed)
        ->and($gateway->transcodeCalls - $callsBefore)->toBe(1);

    // Backdate the failure past the cooldown (as if the user is retrying a
    // while later, e.g. after fixing whatever caused it) and rebuild again.
    $stillFailed->updatedAt = DateTime::now(Timezone::UTC)->minusSeconds(301);
    $stillFailed->save();

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $retried = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);
    $item = BroadcastItemRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->first();

    expect($gateway->transcodeCalls - $callsBefore)->toBe(2)
        ->and($retried?->state)->toBe(AssetState::Ready)
        ->and($item->lastError)->toBeNull();
});

test('video podcast records stable error for unsuitable video asset', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-video-unsuitable');
    podcastFeedCreateAsset(
        $this->container->get(StashdConfig::class),
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Video,
        'original.mkv',
        'video/x-matroska',
        'video-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Video Missing',
        'slug' => 'video-missing-' . bin2hex(random_bytes(3)),
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->body['command'];
    $item = BroadcastItemRecord::select()
        ->where('broadcastId', $broadcast->body['broadcast']['id'])
        ->first();

    expect($command['result']['verify']['ok'])->toBeFalse()
        ->and($item->lastError)->toBe('podcast_video_asset_unavailable');
});

test('podcast rebuild activity does not include raw enclosure tokens', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-activity-redaction');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Activity Feed',
        'slug' => 'activity-feed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));
    $feedToken = podcastFeedTokenFromUrl($broadcast->body['broadcast']['feed_url']);
    $itemToken = podcastFeedTokenFromEpisodeUrl($feedXml);
    $activity = json_encode(ActivityEventRecord::select()->all(), JSON_THROW_ON_ERROR);

    expect($activity)->not->toContain($feedToken)
        ->and($activity)->not->toContain($itemToken);
});

test('manual funding url setting wins over a detected link in episode descriptions', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-funding-manual-wins');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->description = 'Support the show: https://www.patreon.com/example';
    $media->save();

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Manual Funding Feed',
        'slug' => 'manual-funding-' . bin2hex(random_bytes(3)),
        'settings' => ['funding_url' => 'https://example.test/manual-support'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));
    $xml = simplexml_load_string($feedXml);

    $funding = $xml->channel->children('https://podcastindex.org/namespace/1.0')->funding;

    expect((string) $funding->attributes()['url'])->toBe('https://example.test/manual-support')
        ->and((string) $xml->channel->description)->not->toContain('patreon.com');
});

test('detected funding link is used in the feed when no manual setting exists', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-funding-detected');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->description = 'Buy me a coffee at https://ko-fi.com/example';
    $media->save();

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Detected Funding Feed',
        'slug' => 'detected-funding-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));
    $xml = simplexml_load_string($feedXml);

    $funding = $xml->channel->children('https://podcastindex.org/namespace/1.0')->funding;

    expect((string) $funding->attributes()['url'])->toBe('https://ko-fi.com/example');
});

test('podcast feed omits funding tag when no manual or detected funding link exists', function (): void {
    [$headers, $stashId, $mediaItemId] = podcastFeedReadyStash($this, 'podcast-funding-none');
    $config = $this->container->get(StashdConfig::class);
    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'No Funding Feed',
        'slug' => 'no-funding-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));

    expect($feedXml)->not->toContain('<funding');
});

test('funding link detection only scans descriptions of items included in the feed', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('podcast-funding-excluded');
    $config = $this->container->get(StashdConfig::class);

    foreach (StashItemRecord::select()->where('stashId', $stashId)->all() as $stashItem) {
        if ((string) $stashItem->mediaItemId === $mediaItemId) {
            continue;
        }

        $stashItem->state = StashItemState::Hidden;
        $stashItem->save();

        $hiddenMedia = MediaItemRecord::findById(new PrimaryKey((string) $stashItem->mediaItemId));
        $hiddenMedia->description = 'Hidden episode funding: https://www.patreon.com/hidden';
        $hiddenMedia->save();
    }

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $media->state = \App\Vault\MediaItemState::Ready;
    $media->save();

    podcastFeedCreateAsset(
        $config,
        $this->container->get(AssetRepository::class),
        $mediaItemId,
        AssetKind::Audio,
        'original.mp3',
        'audio/mpeg',
        'audio-bytes',
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Funding Excluded Feed',
        'slug' => 'funding-excluded-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $feedXml = (string) file_get_contents(podcastFeedPath($config, $broadcast->body['broadcast']['id']));

    expect($feedXml)->not->toContain('<funding')
        ->and($feedXml)->not->toContain('patreon.com/hidden');
});

/** @return array{0: array{Authorization: string}, 1: string, 2: string} */
function podcastFeedReadyStash(\Tests\IntegrationTestCase $test, string $channel): array
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
    $media->state = \App\Vault\MediaItemState::Ready;
    $media->save();

    return [$headers, $stashId, $mediaItemId];
}

function podcastFeedCreateAsset(
    StashdConfig $config,
    AssetRepository $assets,
    string $mediaItemId,
    AssetKind $kind,
    string $filename,
    string $mimeType,
    string $contents,
): void {
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $path = $config->vaultPath() . '/podcast-tests/' . $media->providerItemId . '/' . $filename;

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
        relativePath: 'podcast-tests/' . $media->providerItemId . '/' . $filename,
        mimeType: $mimeType,
        sizeBytes: strlen($contents),
    );
}

function podcastFeedPath(StashdConfig $config, string $broadcastId): string
{
    $broadcast = BroadcastRecord::select()->get(new PrimaryKey($broadcastId))
        ?? throw new \RuntimeException('Broadcast not found: ' . $broadcastId);

    return (new BroadcastPathBuilder($config))->broadcastFile($broadcast, 'feed.xml');
}

function podcastFeedTokenFromUrl(string $feedUrl): string
{
    $path = parse_url($feedUrl, PHP_URL_PATH);
    $parts = explode('/', trim((string) $path, '/'));

    return $parts[1] ?? '';
}

function podcastFeedTokenFromEpisodeUrl(string $value): string
{
    if (str_contains($value, '<rss')) {
        preg_match('#/items/([^/]+)/episode\.#', $value, $matches);

        return $matches[1] ?? '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    $parts = explode('/', trim((string) $path, '/'));

    return $parts[3] ?? '';
}

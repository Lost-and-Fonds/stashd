<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\Podcasts\PodcastMediaKind;
use App\Broadcasts\Podcasts\PodcastTranscodeFallback;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;

test('video media kind never triggers a fallback regardless of asset state', function (): void {
    [$mediaItemId] = transcodeFallbackTestMediaItem(
        $this->container->get(MediaItemRepository::class),
        $this->container->get(AssetRepository::class),
        'no-pathway',
    );

    $fallback = $this->container->get(PodcastTranscodeFallback::class);

    expect($fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Video))->toBeNull();
});

test('no fallback applies when no video original exists either', function (): void {
    [$mediaItemId] = transcodeFallbackTestMediaItem(
        $this->container->get(MediaItemRepository::class),
        $this->container->get(AssetRepository::class),
        'nothing-available',
    );

    $fallback = $this->container->get(PodcastTranscodeFallback::class);

    expect($fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Audio))->toBeNull();
});

test('a ready video original queues a transcode and creates a pending audio asset', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    [$mediaItemId, $videoAssetId] = transcodeFallbackTestMediaItem(
        $this->container->get(MediaItemRepository::class),
        $assets,
        'queue-transcode',
        withReadyVideo: true,
    );

    $fallback = $this->container->get(PodcastTranscodeFallback::class);
    $code = $fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Audio);

    $audioAsset = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::PodcastAudio);
    $transcodeJobs = JobRecord::select()->where('intent', JobIntent::TranscodePodcastAudio->value)->all();

    expect($code)->toBe('podcast_audio_transcode_pending')
        ->and($audioAsset)->not->toBeNull()
        ->and($audioAsset->state)->toBe(AssetState::Pending)
        ->and((string) $audioAsset->derivedFromAssetId)->toBe($videoAssetId)
        ->and($transcodeJobs)->toHaveCount(1);
});

test('an in-flight transcode is not duplicated on a second call', function (): void {
    [$mediaItemId] = transcodeFallbackTestMediaItem(
        $this->container->get(MediaItemRepository::class),
        $this->container->get(AssetRepository::class),
        'no-duplicate',
        withReadyVideo: true,
    );

    $fallback = $this->container->get(PodcastTranscodeFallback::class);
    $first = $fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Audio);
    $second = $fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Audio);

    $transcodeJobs = JobRecord::select()->where('intent', JobIntent::TranscodePodcastAudio->value)->all();

    expect($first)->toBe('podcast_audio_transcode_pending')
        ->and($second)->toBe('podcast_audio_transcode_pending')
        ->and($transcodeJobs)->toHaveCount(1);
});

test('a failed transcode is not automatically retried', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    [$mediaItemId] = transcodeFallbackTestMediaItem(
        $this->container->get(MediaItemRepository::class),
        $assets,
        'no-retry',
        withReadyVideo: true,
    );

    $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::PodcastAudio,
        kind: AssetKind::Audio,
        state: AssetState::Failed,
    );

    $fallback = $this->container->get(PodcastTranscodeFallback::class);
    $code = $fallback->triggerIfNeeded(MediaItemId::parse($mediaItemId), PodcastMediaKind::Audio);

    $transcodeJobs = JobRecord::select()->where('intent', JobIntent::TranscodePodcastAudio->value)->all();

    expect($code)->toBe('podcast_audio_transcode_failed')
        ->and($transcodeJobs)->toHaveCount(0);
});

/**
 * @return array{0: string, 1: ?string} [mediaItemId, videoAssetId]
 */
function transcodeFallbackTestMediaItem(
    MediaItemRepository $mediaItems,
    AssetRepository $assets,
    string $slug,
    bool $withReadyVideo = false,
): array {
    $media = $mediaItems->create(
        providerKey: 'fake',
        providerItemId: 'transcode-fallback-' . $slug,
        canonicalUri: 'fake://item/transcode-fallback-' . $slug,
        title: 'Transcode Fallback Fixture',
        durationSeconds: 120,
    );

    if (! $withReadyVideo) {
        return [(string) $media->id, null];
    }

    $tempPath = sys_get_temp_dir() . '/stashd-transcode-fallback-' . $slug . '.mp4';
    file_put_contents($tempPath, 'fake-video-bytes');

    $videoAsset = $assets->create(
        mediaItemId: MediaItemId::parse((string) $media->id),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $tempPath,
    );

    return [(string) $media->id, (string) $videoAsset->id];
}

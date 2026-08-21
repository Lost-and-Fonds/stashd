<?php

declare(strict_types=1);

namespace App\Broadcasts\Plugins;

use App\Broadcasts\BroadcastContext;
use App\Broadcasts\BroadcastContextFactory;
use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastPlan;
use App\Broadcasts\BroadcastPlannedSidecar;
use App\Broadcasts\BroadcastPruneResult;
use App\Broadcasts\BroadcastPublishResult;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\BroadcastSidecarType;
use App\Broadcasts\BroadcastVerifyResult;
use App\Broadcasts\FileKind;
use App\Broadcasts\Podcasts\PodcastEpisode;
use App\Broadcasts\Podcasts\PodcastFeedBuilder;
use App\Broadcasts\Podcasts\PodcastFeedMetadata;
use App\Broadcasts\Podcasts\PodcastFeedSettings;
use App\Broadcasts\Podcasts\PodcastGuid;
use App\Broadcasts\Podcasts\PodcastMediaKind;
use App\Broadcasts\Podcasts\PodcastTokenService;
use App\Broadcasts\StashdBroadcast;
use App\Broadcasts\UiControl;
use App\Commands\CommandDispatchService;
use App\Commands\CommandType;
use App\Config\StashdConfig;
use App\Plugins\PluginHostClient;
use App\Stashes\StashItemId;
use App\Stashes\StashItemState;
use App\Support\DurationSeconds;
use App\System\State\StateTransitionService;
use App\Timeline\TimelineMetadataRenderer;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Symfony\Component\Uid\Uuid;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

/**
 * Podcast broadcast plugin — generates RSS podcast feeds with episode media URLs.
 */
#[StashdBroadcast('Podcast', 'RSS podcast feed with episode media URLs.')]
final readonly class PodcastBroadcastPlugin implements \App\Broadcasts\BroadcastPlugin, \App\Broadcasts\BroadcastPluginPresentation, \App\Broadcasts\BroadcastPluginActions, \App\Broadcasts\BroadcastPluginPolicy
{
    public function __construct(
        private BroadcastContextFactory $contextFactory,
        private BroadcastPathBuilder $paths,
        private BroadcastItemRepository $broadcastItems,
        private BroadcastRepository $broadcasts,
        private \App\Broadcasts\Podcasts\PodcastAssetSelector $assets,
        private PodcastTokenService $tokens,
        private \App\Broadcasts\Podcasts\PodcastEpisodeUrlBuilder $urls,
        private PodcastGuid $guids,
        private PodcastFeedBuilder $feedBuilder,
        private StateTransitionService $transitions,
        private \App\Broadcasts\Podcasts\PodcastFundingLinkDetector $fundingDetector,
        private \App\Broadcasts\Podcasts\PodcastTranscodeFallback $transcodeFallback,
        private CommandDispatchService $dispatch,
        private TimelineMetadataRenderer $timeline,
        private StashdConfig $config,
        private \App\Broadcasts\PublishedResourceService $publications,
    ) {
    }

    public function broadcastKeys(): array
    {
        return ['podcast'];
    }

    public function supportedFileKinds(): array
    {
        return [FileKind::Audio, FileKind::Video];
    }

    public function supportsItemRebuild(): bool
    {
        return true;
    }

    public function detailFields(\App\Broadcasts\BroadcastRecord $broadcast): array
    {
        $token = $this->tokens->ensureBroadcastToken($broadcast);

        return [[
            'id' => 'publication-url',
            'label' => 'Feed URL',
            'value' => $this->urls->feedUrl($token),
            'kind' => 'url',
            'link' => $this->urls->feedUrl($token),
        ]];
    }

    public function actions(\App\Broadcasts\BroadcastRecord $broadcast): array
    {
        return [[
            'id' => 'rotate-publication-token',
            'label' => 'Rotate token',
            'intent' => 'rotate_token',
            'confirmation' => true,
        ]];
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function invokeAction(\App\Broadcasts\BroadcastRecord $broadcast, string $intent, array $payload = []): array
    {
        if ($intent !== 'rotate_token') {
            throw BroadcastException::withCode('broadcast_action_unsupported', 'Broadcast action is unsupported.');
        }

        return $this->tokens->rotateBroadcastToken($broadcast)->toArray();
    }

    public function acceptsDownloadPolicy(\App\Broadcasts\BroadcastRecord $broadcast, \App\Stashes\DownloadPolicy $policy): bool
    {
        return match ($policy) {
            \App\Stashes\DownloadPolicy::MetadataOnly => false,
            \App\Stashes\DownloadPolicy::AudioOnly => $this->preferredMediaKindFor($broadcast) !== PodcastMediaKind::Video,
            \App\Stashes\DownloadPolicy::Video, \App\Stashes\DownloadPolicy::ManualDownload => true,
        };
    }

    public function derivedWorkCount(\App\Broadcasts\BroadcastContext $context): int
    {
        if ($this->preferredMediaKindFor($context->broadcast) !== PodcastMediaKind::Audio) {
            return 0;
        }

        return count(array_filter(
            $context->vaultOriginals,
            static fn ($asset): bool => $asset?->kind === \App\Vault\AssetKind::Video,
        ));
    }

    public function prunesAfterPublish(): bool
    {
        return false;
    }

    public function uiControls(): array
    {
        $path = dirname(__DIR__, 3) . '/plugins/podcast/plugin.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $options = is_array($manifest) && is_array($manifest['ui_options'] ?? null)
            ? $manifest['ui_options']
            : [];

        return array_values(array_filter(array_map(
            static function (mixed $option): ?UiControl {
                if (! is_array($option) || ! is_string($option['key'] ?? null) || ! is_string($option['label'] ?? null)) {
                    return null;
                }

                $choices = is_array($option['choices'] ?? null)
                    ? array_values(array_filter($option['choices'], 'is_string'))
                    : [];

                return new UiControl(
                    name: $option['key'],
                    label: $option['label'],
                    type: is_string($option['type'] ?? null) ? $option['type'] : 'text',
                    default: $option['default'] ?? null,
                    options: $choices,
                    description: is_string($option['description'] ?? null) ? $option['description'] : null,
                    required: ($option['required'] ?? false) === true,
                );
            },
            $options,
        )));
    }

    public function plan(BroadcastContext $context): BroadcastPlan
    {
        $broadcastId = (string) $context->broadcast->id;

        return new BroadcastPlan(
            broadcastId: $broadcastId,
            broadcastRoot: $this->paths->broadcastRoot($context->broadcast),
            files: [],
            sidecars: [
                new BroadcastPlannedSidecar(
                    kind: BroadcastSidecarType::FeedXml,
                    relativePath: $this->paths->relativeFile('feed.xml'),
                    absolutePath: $this->feedPath($context),
                    content: '',
                ),
            ],
            skippedStashItemIds: $this->skippedStashItemIds($context),
        );
    }

    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult
    {
        $broadcastToken = $this->tokens->ensureBroadcastToken($context->broadcast);
        $episodes = [];
        $includedDescriptions = [];
        $failed = [];
        $included = 0;

        $this->paths->claimRoot($context->broadcast);
        $feedPublication = $this->publications->publishFile(
            $context->broadcast,
            'feed.xml',
            'application/rss+xml',
            access: 'credential',
            downloadName: 'feed.xml',
        );
        $feedUrl = $this->publications->url($feedPublication);

        foreach ($this->contextFactory->publishableStashItems($context) as $stashItem) {

            $mediaItem = $context->mediaItems[(string) $stashItem->mediaItemId] ?? null;
            $item = $this->findOrCreateItem($context, $stashItem);

            if ($mediaItem === null) {
                $this->markItemFailed($item, 'podcast_media_item_unavailable');
                $failed[] = (string) $stashItem->id;

                continue;
            }

            $captionSettings = PodcastFeedSettings::fromArray($this->settings($context));
            if ($captionSettings->captions !== 'off' && $this->assets->captionAsset($stashItem->mediaItemId) === null) {
                $this->dispatch->dispatch(CommandType::AssetDownloadCaptions, [
                    'media_item_id' => (string) $stashItem->mediaItemId,
                    'languages' => $captionSettings->captionLanguages,
                    'include_auto' => $captionSettings->captions === 'creator_or_auto',
                ]);
            }

            $kind = $this->preferredMediaKind($context);
            $selection = $this->selectAsset($context, $stashItem->mediaItemId);

            if ($selection === null) {
                $fallbackCode = $this->transcodeFallback->triggerIfNeeded($stashItem->mediaItemId, $kind);

                // A pending transcode is background work in progress, not a
                // failure -- landing it in Processing (rather than Failed)
                // matters beyond cosmetics: Failed can only transition back
                // to Processing, so if this landed in Failed, the very next
                // verify() call's attempt to move it to Stale would be
                // blocked by BroadcastItemState's transition rules and the
                // item would stay stuck showing Failed indefinitely.
                if ($fallbackCode === 'podcast_audio_transcode_pending') {
                    $this->markItemProcessing($item, $fallbackCode);
                } else {
                    $this->markItemFailed($item, $fallbackCode ?? $this->unavailableErrorCode($kind));
                }

                $failed[] = (string) $stashItem->id;

                continue;
            }

            $itemToken = $this->tokens->ensureItemToken($item);
            $this->markItemReady($context, $item, $mediaItem);
            $mediaPublication = $this->publications->publishAsset(
                $context->broadcast,
                $selection->asset,
                $selection->mimeType,
                access: 'credential',
            );
            $episodes[] = $this->episode(
                $context,
                $stashItem,
                $mediaItem,
                $item,
                $selection,
                $broadcastToken,
                $itemToken,
                $this->publications->url($mediaPublication),
            );
            $includedDescriptions[] = $mediaItem->description;
            $included++;
        }

        $feedPath = $this->feedPath($context);
        $this->writeFeed($feedPath, $this->feedContent($context, $broadcastToken, $episodes, $includedDescriptions, $feedUrl));

        return new BroadcastPublishResult(
            publishedCount: 1,
            skippedCount: count($failed),
            publishedPaths: [$feedPath],
            failedStashItemIds: $failed,
        );
    }

    public function verify(BroadcastContext $context): BroadcastVerifyResult
    {
        $this->paths->assertOwnsRoot($context->broadcast);
        $valid = [];
        $stale = [];
        $missing = [];
        $feedPath = $this->feedPath($context);

        if (! is_file($feedPath) || ! is_readable($feedPath)) {
            $missing[] = 'feed.xml';
        }

        foreach ($this->contextFactory->publishableStashItems($context) as $stashItem) {

            $item = $this->broadcastItems->findByBroadcastAndStashItem(
                BroadcastId::fromPrimaryKey($context->broadcast->id),
                StashItemId::fromPrimaryKey($stashItem->id),
            );

            if ($item === null) {
                $stale[] = (string) $stashItem->id;

                continue;
            }

            $kind = $this->preferredMediaKind($context);

            if ($this->selectAsset($context, $stashItem->mediaItemId) === null) {
                $fallbackCode = $this->transcodeFallback->triggerIfNeeded($stashItem->mediaItemId, $kind);
                $this->markItemStale($item, $fallbackCode ?? $this->unavailableErrorCode($kind));
                $stale[] = (string) $item->id;

                continue;
            }

            if ($this->tokens->itemToken($item) === null) {
                $this->markItemStale($item, 'podcast_item_token_unavailable');
                $stale[] = (string) $item->id;

                continue;
            }

            $item->lastVerifiedAt = DateTime::now(Timezone::UTC);
            $item->lastError = null;

            if ($item->state !== BroadcastItemState::Ready) {
                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
            } else {
                $this->broadcastItems->save($item);
            }

            $valid[] = (string) $item->id;
        }

        $staleCount = count($stale) + count($missing);

        return new BroadcastVerifyResult(
            ok: $staleCount === 0,
            validCount: count($valid),
            staleCount: $staleCount,
            validItemIds: $valid,
            staleItemIds: $stale,
            missingItemIds: $missing,
        );
    }

    public function prune(BroadcastContext $context): BroadcastPruneResult
    {
        $this->paths->assertOwnsRoot($context->broadcast);
        $feedPath = $this->feedPath($context);

        if (is_file($feedPath) && @unlink($feedPath)) {
            return new BroadcastPruneResult(removedCount: 1, removedPaths: [$feedPath]);
        }

        return new BroadcastPruneResult(removedCount: 0, removedPaths: []);
    }

    private function findOrCreateItem(BroadcastContext $context, \App\Stashes\StashItemRecord $stashItem): BroadcastItemRecord
    {
        $broadcastId = (string) $context->broadcast->id;
        $item = $this->broadcastItems->findByBroadcastAndStashItem(
            BroadcastId::parse($broadcastId),
            StashItemId::fromPrimaryKey($stashItem->id),
        );

        return $item ?? $this->broadcastItems->create(
            broadcastId: BroadcastId::parse($broadcastId),
            stashItemId: StashItemId::fromPrimaryKey($stashItem->id),
            mediaItemId: $stashItem->mediaItemId,
        );
    }

    private function markItemReady(BroadcastContext $context, BroadcastItemRecord $item, MediaItemRecord $mediaItem): void
    {
        if ($item->state !== BroadcastItemState::Processing) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
        }

        $item->publishedPath = null;
        $item->publishedUri = null;
        $item->lastPublishedAt = DateTime::now(Timezone::UTC);
        $item->lastError = null;
        $this->broadcastItems->save($item);
        $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
    }

    private function markItemProcessing(BroadcastItemRecord $item, string $reason): void
    {
        if ($item->state !== BroadcastItemState::Processing && $item->state->canTransitionTo(BroadcastItemState::Processing)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
        }

        $item->lastError = $reason;
        $this->broadcastItems->save($item);
    }

    private function markItemFailed(BroadcastItemRecord $item, string $reason): void
    {
        if ($item->state !== BroadcastItemState::Processing && $item->state->canTransitionTo(BroadcastItemState::Processing)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
        }

        $item->lastError = $reason;
        $this->broadcastItems->save($item);

        if ($item->state->canTransitionTo(BroadcastItemState::Failed)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Failed);
        }
    }

    private function markItemStale(BroadcastItemRecord $item, string $reason): void
    {
        $item->lastError = $reason;
        $this->broadcastItems->save($item);

        if ($item->state->canTransitionTo(BroadcastItemState::Stale)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Stale);
        }
    }

    private function episode(
        BroadcastContext $context,
        \App\Stashes\StashItemRecord $stashItem,
        MediaItemRecord $mediaItem,
        BroadcastItemRecord $item,
        \App\Broadcasts\Podcasts\PodcastAssetSelection $selection,
        string $broadcastToken,
        string $itemToken,
        ?string $publishedMediaUrl = null,
    ): PodcastEpisode {
        return new PodcastEpisode(
            guid: $this->guids->forItem($item),
            title: $this->episodeTitle($stashItem, $mediaItem),
            description: $this->episodeDescription($stashItem, $mediaItem),
            publishedAt: $mediaItem->publishedAt ?? $stashItem->firstSeenAt ?? $context->broadcast->createdAt ?? DateTime::now(Timezone::UTC),
            enclosureUrl: $this->urls->episodeUrl($broadcastToken, $itemToken, $selection->extension),
            enclosureLength: $selection->length,
            enclosureMimeType: $selection->mimeType,
            durationSeconds: DurationSeconds::toSeconds($mediaItem->durationSeconds),
            imageUrl: $this->assets->artworkAsset($stashItem->mediaItemId) === null
                ? null
                : $this->urls->artworkUrl($broadcastToken, $itemToken),
            transcriptUrl: $this->assets->captionAsset($stashItem->mediaItemId) === null
                ? null
                : $this->urls->transcriptUrl($broadcastToken, $itemToken),
            transcriptMimeType: $this->assets->captionAsset($stashItem->mediaItemId)?->mimeType,
            transcriptLanguage: $this->assets->captionAsset($stashItem->mediaItemId)?->language,
            chapterUrl: $this->timeline->hasEntries($item->mediaItemId)
                ? $this->urls->chapterUrl($broadcastToken, $itemToken)
                : null,
            publicationToken: $itemToken,
            publishedMediaUrl: $publishedMediaUrl,
        );
    }

    /** @param list<PodcastEpisode> $episodes
     *  @param list<string|null> $includedDescriptions
     */
    private function feedContent(BroadcastContext $context, string $broadcastToken, array $episodes, array $includedDescriptions, ?string $feedUrl = null): string
    {
        $component = getenv('STASHD_BROADCAST_PLUGIN_COMPONENT');
        $component = is_string($component) && trim($component) !== ''
            ? trim($component)
            : dirname(__DIR__, 3) . '/target/wasm32-wasip2/release/stashd_podcast_plugin.wasm';

        $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : '/tmp/stashd-plugin-host.sock';

        if (! is_file($component) || ! file_exists($socket)) {
            return $this->feedBuilder->build($this->metadata($context, $broadcastToken, $includedDescriptions), $episodes);
        }

        $stage = sys_get_temp_dir() . '/stashd-broadcast-plugin-' . bin2hex(random_bytes(8));
        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw BroadcastException::withCode('podcast_feed_write_failed', 'Podcast feed staging could not be created.');
        }

        try {
            $settings = $this->settings($context);
            $request = [
                'broadcast_id' => (string) $context->broadcast->id,
                'settings' => array_map(
                    static fn (string $key, mixed $value): array => [
                        'key' => $key,
                        'value' => is_bool($value)
                            ? ['kind' => 'boolean', 'value' => $value]
                            : ['kind' => 'text', 'value' => is_scalar($value) ? (string) $value : ''],
                    ],
                    array_keys($settings),
                    $settings,
                ),
                'public_base_url' => rtrim($this->config->publicUrl, '/'),
                'broadcast_token' => $broadcastToken,
                'feed_url' => $feedUrl ?? $this->urls->feedUrl($broadcastToken),
                'episodes' => array_map(
                    static fn (PodcastEpisode $episode): array => [
                        'id' => $episode->guid,
                        'publication_token' => $episode->publicationToken ?? '',
                        'title' => $episode->title,
                        'description' => $episode->description,
                        'published_at' => $episode->publishedAt->toNativeDateTime()->format(DATE_RSS),
                        'duration_seconds' => $episode->durationSeconds,
                        // The component owns the public URL shape.  Core only
                        // supplies the artifact's presentation extension.
                        'media_reference' => pathinfo(
                            (string) parse_url($episode->enclosureUrl, PHP_URL_PATH),
                            PATHINFO_EXTENSION,
                        ) ?: 'mp3',
                        'media_url' => $episode->publishedMediaUrl,
                        'media_type' => $episode->enclosureMimeType,
                        'media_size_bytes' => $episode->enclosureLength,
                        'artwork_reference' => $episode->imageUrl === null ? null : 'available',
                        'transcript_reference' => $episode->transcriptUrl === null ? null : 'available',
                        'chapter_reference' => $episode->chapterUrl === null ? null : 'available',
                    ],
                    $episodes,
                ),
            ];
            $result = (new PluginHostClient($socket))->publishBroadcast($component, $stage, $request);
            $publication = $result->publication;
            $artifact = is_array($publication['artifact'] ?? null) ? $publication['artifact'] : [];
            $reference = $artifact['reference'] ?? null;
            if (! is_string($reference) || $reference === '') {
                throw BroadcastException::withCode('podcast_feed_write_failed', 'Podcast plugin returned no feed artifact.');
            }
            $staged = $stage . '/' . $reference;
            $xml = @file_get_contents($staged);
            if ($xml === false) {
                throw BroadcastException::withCode('podcast_feed_write_failed', 'Podcast plugin feed artifact could not be read.');
            }

            return $xml;
        } finally {
            foreach (glob($stage . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($stage);
        }
    }

    /** @param list<string|null> $includedDescriptions */
    private function metadata(BroadcastContext $context, string $broadcastToken, array $includedDescriptions): PodcastFeedMetadata
    {
        $settings = PodcastFeedSettings::fromArray($this->settings($context));
        $title = $settings->title
            ?? $context->stash->name
            ?? $context->broadcast->name;
        $description = $settings->description
            ?? $context->stash->description
            ?? 'Private Stashd podcast feed.';
        $fundingUrl = $settings->fundingUrl
            ?? $this->fundingDetector->detect($includedDescriptions);

        return new PodcastFeedMetadata(
            title: $title,
            description: $description,
            feedUrl: $this->urls->feedUrl($broadcastToken),
            linkUrl: $settings->linkUrl,
            author: $settings->author,
            imageUrl: $settings->imageUrl ?? $this->nonEmptyString($context->stash->iconUri),
            fundingUrl: $fundingUrl,
            language: $settings->language,
            explicit: $settings->explicit,
            complete: $settings->complete,
            podcastGuid: $this->feedGuid($context),
        );
    }

    private function feedGuid(BroadcastContext $context): string
    {
        $settings = $context->broadcast->settings ?? [];
        $guid = $settings['podcast_guid'] ?? null;

        if (is_string($guid) && Uuid::isValid($guid)) {
            return $guid;
        }

        $guid = Uuid::v4()->toRfc4122();
        $settings['podcast_guid'] = $guid;
        $context->broadcast->settings = $settings;
        $this->broadcasts->save($context->broadcast);

        return $guid;
    }

    private function episodeTitle(\App\Stashes\StashItemRecord $stashItem, MediaItemRecord $mediaItem): string
    {
        return $this->nonEmptyString($mediaItem->title)
            ?? $this->nonEmptyString($stashItem->displayTitle)
            ?? 'Untitled episode';
    }

    private function episodeDescription(\App\Stashes\StashItemRecord $stashItem, MediaItemRecord $mediaItem): string
    {
        return $this->nonEmptyString($mediaItem->description)
            ?? $this->nonEmptyString($stashItem->displayDescription)
            ?? $this->episodeTitle($stashItem, $mediaItem);
    }

    /** @return array<string, mixed> */
    private function settings(BroadcastContext $context): array
    {
        return $context->broadcast->settings ?? [];
    }

    private function preferredMediaKindFor(\App\Broadcasts\BroadcastRecord $broadcast): PodcastMediaKind
    {
        return PodcastMediaKind::forBroadcast($broadcast);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function feedPath(BroadcastContext $context): string
    {
        return $this->paths->broadcastFile($context->broadcast, 'feed.xml');
    }

    private function writeFeed(string $path, string $xml): void
    {
        $directory = dirname($path);

        try {
            create_directory($directory, 0o775);
        } catch (FilesystemException) {
            throw BroadcastException::withCode('podcast_feed_write_failed', 'Podcast feed could not be written.');
        }

        if (file_put_contents($path, $xml) === false) {
            throw BroadcastException::withCode('podcast_feed_write_failed', 'Podcast feed could not be written.');
        }
    }

    /** @return list<string> */
    private function skippedStashItemIds(BroadcastContext $context): array
    {
        $skipped = [];

        foreach ($context->stashItems as $stashItem) {
            if ($stashItem->state !== StashItemState::Active) {
                $skipped[] = (string) $stashItem->id;
            }
        }

        return $skipped;
    }

    private function preferredMediaKind(BroadcastContext $context): PodcastMediaKind
    {
        return PodcastMediaKind::forBroadcast($context->broadcast);
    }

    private function selectAsset(BroadcastContext $context, MediaItemId $mediaItemId): ?\App\Broadcasts\Podcasts\PodcastAssetSelection
    {
        return match ($this->preferredMediaKind($context)) {
            PodcastMediaKind::Audio => $this->assets->audioAsset($mediaItemId),
            PodcastMediaKind::Video => $this->assets->videoAsset($mediaItemId),
        };
    }

    private function unavailableErrorCode(PodcastMediaKind $kind): string
    {
        return match ($kind) {
            PodcastMediaKind::Audio => 'podcast_audio_asset_unavailable',
            PodcastMediaKind::Video => 'podcast_video_asset_unavailable',
        };
    }
}

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
final readonly class PodcastBroadcastPlugin implements \App\Broadcasts\BroadcastPlugin
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

    public function uiControls(): array
    {
        return [
            new UiControl('title', 'Podcast Title', 'text'),
            new UiControl('description', 'Podcast Description', 'text'),
            new UiControl('author', 'Author', 'text'),
            new UiControl('language', 'Language', 'text', 'en'),
            new UiControl('explicit', 'Explicit', 'select', 'false', ['false', 'true']),
            new UiControl('complete', 'Complete', 'select', 'false', ['false', 'true']),
            new UiControl('captions', 'Captions', 'select', 'off', ['off', 'creator_only', 'creator_or_auto']),
            new UiControl('caption_languages', 'Caption languages', 'text', 'en'),
            new UiControl('funding_url', 'Funding URL', 'text'),
            new UiControl('media_kind', 'Media Kind', 'select', null, ['audio', 'video']),
        ];
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
            $episodes[] = $this->episode($context, $stashItem, $mediaItem, $item, $selection, $broadcastToken, $itemToken);
            $includedDescriptions[] = $mediaItem->description;
            $included++;
        }

        $feedPath = $this->feedPath($context);
        $this->writeFeed($feedPath, $this->feedBuilder->build($this->metadata($context, $broadcastToken, $includedDescriptions), $episodes));

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
        );
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

<?php

declare(strict_types=1);

namespace App\Broadcasts\Plugins;

use App\Broadcasts\BroadcastContext;
use App\Broadcasts\BroadcastContextFactory;
use App\Broadcasts\BroadcastFilenameBuilder;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastNfoBuilder;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastPlan;
use App\Broadcasts\BroadcastPlannedFile;
use App\Broadcasts\BroadcastPlannedSidecar;
use App\Broadcasts\BroadcastPlugin;
use App\Broadcasts\BroadcastPruneResult;
use App\Broadcasts\BroadcastPublishResult;
use App\Broadcasts\BroadcastSidecarType;
use App\Broadcasts\BroadcastSidecarWriter;
use App\Broadcasts\BroadcastVerifyResult;
use App\Broadcasts\FileKind;
use App\Broadcasts\HardlinkPublisher;
use App\Broadcasts\UiControl;
use App\Commands\CommandDispatchService;
use App\Commands\CommandType;
use App\Stashes\StashItemId;
use App\System\State\StateTransitionService;
use App\Vault\AssetId;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

/**
 * Abstract base for series-layout broadcast plugins.
 *
 * Provides shared plan/publish/verify/prune logic for filesystem-based
 * series layouts. Subclasses override profile() to control naming
 * conventions, sidecar policies, and media server compatibility.
 */
abstract class AbstractSeriesBroadcastPlugin implements BroadcastPlugin
{
    public function __construct(
        protected BroadcastContextFactory $contextFactory,
        protected BroadcastPathBuilder $paths,
        protected BroadcastFilenameBuilder $filenames,
        protected BroadcastNfoBuilder $nfos,
        protected BroadcastSidecarWriter $sidecarWriter,
        protected HardlinkPublisher $hardlinks,
        protected BroadcastItemRepository $broadcastItems,
        protected AssetRepository $assets,
        protected StateTransitionService $transitions,
        protected CommandDispatchService $dispatch,
    ) {
    }

    /**
     * Get the series layout profile (naming convention, sidecar policy, etc.).
     */
    abstract protected function profile(): SeriesFormatOptions;

    /**
     * Get the broadcast key for this plugin.
     */
    abstract protected function broadcastKey(): string;

    public function broadcastKeys(): array
    {
        return [$this->broadcastKey()];
    }

    public function supportedFileKinds(): array
    {
        return [FileKind::Video];
    }

    public function supportsItemRebuild(): bool
    {
        return true;
    }

    public function uiControls(): array
    {
        return [
            new UiControl('captions', 'Captions', 'select', 'off', ['off', 'creator_only', 'creator_or_auto']),
            new UiControl('caption_languages', 'Caption languages', 'text', 'en'),
        ];
    }

    public function plan(BroadcastContext $context): BroadcastPlan
    {
        $profile = $this->profile();
        $broadcastId = (string) $context->broadcast->id;
        $root = $this->paths->broadcastRoot($context->broadcast);
        $files = [];
        $sidecars = [];
        $skipped = [];
        $episodePositions = [];
        $tvShowNfoAdded = false;
        $seasonMapping = $context->seasonMapping();
        $stashItems = $this->contextFactory->publishableStashItems($context);

        usort($stashItems, function ($left, $right) use ($context, $seasonMapping): int {
            $leftSeason = max(1, $seasonMapping->seasonFor($left->stashInputId?->toString()) ?? $left->seasonNumber ?? 1);
            $rightSeason = max(1, $seasonMapping->seasonFor($right->stashInputId?->toString()) ?? $right->seasonNumber ?? 1);

            if ($leftSeason !== $rightSeason) {
                return $leftSeason <=> $rightSeason;
            }

            $leftPublishedAt = $context->mediaItems[(string) $left->mediaItemId]->publishedAt;
            $rightPublishedAt = $context->mediaItems[(string) $right->mediaItemId]->publishedAt;

            if ($leftPublishedAt !== null && $rightPublishedAt !== null) {
                if ($leftPublishedAt->isBefore($rightPublishedAt)) {
                    return -1;
                }

                if ($leftPublishedAt->isAfter($rightPublishedAt)) {
                    return 1;
                }
            } elseif ($leftPublishedAt !== null) {
                return -1;
            } elseif ($rightPublishedAt !== null) {
                return 1;
            }

            return [$left->position ?? PHP_INT_MAX, (string) $left->id]
                <=> [$right->position ?? PHP_INT_MAX, (string) $right->id];
        });

        foreach ($stashItems as $stashItem) {
            $vault = $context->vaultOriginals[(string) $stashItem->mediaItemId] ?? null;
            $mediaItem = $context->mediaItems[(string) $stashItem->mediaItemId] ?? null;

            if ($vault === null || $mediaItem === null || $vault->path === null) {
                $skipped[] = (string) $stashItem->id;

                continue;
            }

            $seasonOverride = $seasonMapping->seasonFor($stashItem->stashInputId?->toString());
            $seasonNumber = max(1, $seasonOverride ?? $stashItem->seasonNumber ?? 1);
            $position = ($episodePositions[$seasonNumber] ?? 0) + 1;
            $episodePositions[$seasonNumber] = $position;
            $season = $this->filenames->seasonFolder($stashItem, $seasonOverride);
            $filename = $profile->mediaServerEpisodeNaming
                ? $this->filenames->mediaServerEpisodeFilename($stashItem, $mediaItem, $vault->path, $position, $seasonOverride)
                : $this->filenames->episodeFilename($stashItem, $mediaItem, $vault->path, $position);
            $relative = $this->paths->relativeFile($season, $filename);
            $absolute = $this->paths->broadcastFile($context->broadcast, $season, $filename);

            $files[] = new BroadcastPlannedFile(
                stashItemId: (string) $stashItem->id,
                mediaItemId: (string) $stashItem->mediaItemId,
                sourceAssetId: (string) $vault->id,
                sourcePath: $vault->path,
                relativePath: $relative,
                absolutePath: $absolute,
                filename: $filename,
            );

            if ($profile->generateNfo) {
                if (! $tvShowNfoAdded) {
                    $tvShowRelative = $this->paths->relativeFile('tvshow.nfo');
                    $sidecars[] = new BroadcastPlannedSidecar(
                        kind: BroadcastSidecarType::TvShowNfo,
                        relativePath: $tvShowRelative,
                        absolutePath: $this->paths->broadcastFile($context->broadcast, 'tvshow.nfo'),
                        content: $this->nfos->tvShowNfo($context->broadcast->name),
                    );
                    $tvShowNfoAdded = true;
                }

                $episodeNumber = max(1, $stashItem->episodeNumber ?? $position);
                $episodeNfoName = $this->nfos->episodeNfoFilename($filename);
                $sidecars[] = new BroadcastPlannedSidecar(
                    kind: BroadcastSidecarType::EpisodeNfo,
                    relativePath: $this->paths->relativeFile($season, $episodeNfoName),
                    absolutePath: $this->paths->broadcastFile($context->broadcast, $season, $episodeNfoName),
                    content: $this->nfos->episodeNfo($stashItem, $mediaItem, $seasonNumber, $episodeNumber),
                    stashItemId: (string) $stashItem->id,
                    mediaItemId: (string) $stashItem->mediaItemId,
                );
            }

            // ponytail: Vault currently exposes one subtitle per media item; plan multiple sidecars when it supports multiple tracks.
            $subtitle = $this->captionMode($context) === 'off'
                ? null
                : $this->readySubtitle($stashItem->mediaItemId);

            if ($subtitle?->path !== null) {
                $language = preg_replace('/[^a-z0-9_-]+/i', '-', $subtitle->language ?? 'und') ?: 'und';
                $extension = preg_replace('/[^a-z0-9]+/i', '', pathinfo($subtitle->path, PATHINFO_EXTENSION)) ?: 'vtt';
                $subtitleFilename = pathinfo($filename, PATHINFO_FILENAME) . ".{$language}.{$extension}";
                $sidecars[] = new BroadcastPlannedSidecar(
                    kind: BroadcastSidecarType::Subtitle,
                    relativePath: $this->paths->relativeFile($season, $subtitleFilename),
                    absolutePath: $this->paths->broadcastFile($context->broadcast, $season, $subtitleFilename),
                    content: '',
                    stashItemId: (string) $stashItem->id,
                    mediaItemId: (string) $stashItem->mediaItemId,
                );
            }
        }

        if ($profile->attemptPosterHardlink) {
            $posterSidecar = $this->planPosterSidecar($context);

            if ($posterSidecar !== null) {
                $sidecars[] = $posterSidecar;
            }
        }

        return new BroadcastPlan(
            broadcastId: $broadcastId,
            broadcastRoot: $root,
            files: $files,
            sidecars: $sidecars,
            skippedStashItemIds: $skipped,
        );
    }

    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult
    {
        $broadcastId = $plan->broadcastId;
        $root = $this->paths->claimRoot($context->broadcast);
        $publishedPaths = [];
        $failedStashItemIds = [];
        $publishedCount = 0;

        $this->dispatchMissingCaptions($context, $plan);

        foreach ($plan->files as $planned) {
            $item = $this->broadcastItems->findByBroadcastAndStashItem(
                BroadcastId::parse($broadcastId),
                StashItemId::parse($planned->stashItemId),
            );

            if ($item === null) {
                $item = $this->broadcastItems->create(
                    broadcastId: BroadcastId::parse($broadcastId),
                    stashItemId: StashItemId::parse($planned->stashItemId),
                    mediaItemId: MediaItemId::parse($planned->mediaItemId),
                );
            }

            if ($item->state !== BroadcastItemState::Processing) {
                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
            }

            try {
                $this->hardlinks->publishHardlink($planned->sourcePath, $planned->absolutePath, $root);

                $item->publishedPath = $planned->absolutePath;
                $item->publishedUri = null;
                $item->lastPublishedAt = DateTime::now(Timezone::UTC);
                $item->lastError = null;
                $this->broadcastItems->save($item);

                $this->upsertPublishedAsset(
                    broadcastId: $broadcastId,
                    broadcastItemId: (string) $item->id,
                    mediaItemId: $planned->mediaItemId,
                    sourceAssetId: $planned->sourceAssetId,
                    path: $planned->absolutePath,
                    relativePath: $planned->relativePath,
                    sourcePath: $planned->sourcePath,
                    role: AssetRole::Hardlink,
                );

                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
                $publishedPaths[] = $planned->absolutePath;
                $publishedCount++;
            } catch (\App\Broadcasts\BroadcastException $exception) {
                $item->lastError = $exception->errorCode . ': ' . $exception->getMessage();
                $this->broadcastItems->save($item);
                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Failed);
                $failedStashItemIds[] = $planned->stashItemId;

                throw $exception;
            }
        }

        foreach ($plan->sidecars as $sidecar) {
            if ($sidecar->kind === BroadcastSidecarType::Poster) {
                $this->publishPosterSidecar($sidecar, $root);

                continue;
            }

            if ($sidecar->kind === BroadcastSidecarType::Subtitle) {
                if ($this->publishSubtitleSidecar($sidecar, $root)) {
                    $publishedPaths[] = $sidecar->absolutePath;
                }

                continue;
            }

            $this->sidecarWriter->write($sidecar->absolutePath, $sidecar->content);
            $publishedPaths[] = $sidecar->absolutePath;
        }

        return new BroadcastPublishResult(
            publishedCount: $publishedCount,
            skippedCount: 0,
            publishedPaths: $publishedPaths,
            failedStashItemIds: $failedStashItemIds,
        );
    }

    public function verify(BroadcastContext $context): BroadcastVerifyResult
    {
        $broadcastId = (string) $context->broadcast->id;
        $this->paths->assertOwnsRoot($context->broadcast);
        $validItemIds = [];
        $staleItemIds = [];
        $missingItemIds = [];
        $invalidLinkItemIds = [];

        $plan = $this->plan($context);
        $plannedByStashItem = [];

        foreach ($plan->files as $file) {
            $plannedByStashItem[$file->stashItemId] = $file;
        }

        foreach ($this->broadcastItems->listForBroadcast(BroadcastId::parse($broadcastId)) as $item) {
            $planned = $plannedByStashItem[(string) $item->stashItemId] ?? null;

            if ($planned === null) {
                if ($item->publishedPath !== null && is_file($item->publishedPath)) {
                    $staleItemIds[] = (string) $item->id;
                    $this->markItemStale($item, 'source_item_no_longer_publishable');
                } elseif ($item->publishedPath !== null) {
                    $missingItemIds[] = (string) $item->id;
                    $this->markItemStale($item, 'generated_file_missing');
                }

                continue;
            }

            $path = $item->publishedPath ?? $planned->absolutePath;

            if (! is_file($path)) {
                $missingItemIds[] = (string) $item->id;
                $this->markItemStale($item, 'generated_file_missing');

                continue;
            }

            if (! $this->hardlinks->verifyHardlink($planned->sourcePath, $path)) {
                $invalidLinkItemIds[] = (string) $item->id;
                $this->markItemStale($item, 'hardlink_target_invalid');

                continue;
            }

            if ($item->state !== BroadcastItemState::Ready) {
                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
            }

            $item->lastVerifiedAt = DateTime::now(Timezone::UTC);
            $item->lastError = null;
            $this->broadcastItems->save($item);
            $validItemIds[] = (string) $item->id;
        }

        foreach ($plan->sidecars as $sidecar) {
            if ($sidecar->kind === BroadcastSidecarType::Poster) {
                continue;
            }

            if ($sidecar->kind === BroadcastSidecarType::Subtitle) {
                $subtitle = $sidecar->mediaItemId === null
                    ? null
                    : $this->readySubtitle(MediaItemId::parse($sidecar->mediaItemId));

                if ($subtitle?->path === null || ! $this->hardlinks->verifyHardlink($subtitle->path, $sidecar->absolutePath)) {
                    $staleItemIds[] = 'sidecar:' . $sidecar->relativePath;
                }

                continue;
            }

            if (! is_file($sidecar->absolutePath)) {
                $staleItemIds[] = 'sidecar:' . $sidecar->relativePath;
            }
        }

        $staleCount = count($staleItemIds) + count($missingItemIds) + count($invalidLinkItemIds);

        return new BroadcastVerifyResult(
            ok: $staleCount === 0 && count($validItemIds) > 0,
            validCount: count($validItemIds),
            staleCount: $staleCount,
            validItemIds: $validItemIds,
            staleItemIds: $staleItemIds,
            missingItemIds: $missingItemIds,
            invalidLinkItemIds: $invalidLinkItemIds,
        );
    }

    public function prune(BroadcastContext $context): BroadcastPruneResult
    {
        $broadcastId = (string) $context->broadcast->id;
        $root = $this->paths->assertOwnsRoot($context->broadcast);
        $plan = $this->plan($context);
        $keepPaths = array_map(static fn (BroadcastPlannedFile $file): string => $file->absolutePath, $plan->files);

        foreach ($plan->sidecars as $sidecar) {
            $keepPaths[] = $sidecar->absolutePath;
        }

        $keepPaths[] = $root . '/.stashd-broadcast';
        $keepLookup = array_fill_keys($keepPaths, true);
        $removed = [];

        if (! is_dir($root)) {
            return new BroadcastPruneResult(removedCount: 0, removedPaths: []);
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (isset($keepLookup[$path])) {
                continue;
            }

            if (@unlink($path)) {
                $removed[] = $path;
            }
        }

        $this->pruneEmptyDirectories($root);

        return new BroadcastPruneResult(
            removedCount: count($removed),
            removedPaths: $removed,
        );
    }

    private function planPosterSidecar(BroadcastContext $context): ?BroadcastPlannedSidecar
    {
        foreach ($context->stashItems as $stashItem) {
            $thumbnail = $this->assets->findByMediaItemAndRole(
                $stashItem->mediaItemId,
                AssetRole::SourceThumbnail,
            );

            if (
                $thumbnail === null
                || $thumbnail->state !== AssetState::Ready
                || $thumbnail->path === null
                || ! is_file($thumbnail->path)
            ) {
                continue;
            }

            $extension = pathinfo($thumbnail->path, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'poster.' . $extension;

            return new BroadcastPlannedSidecar(
                kind: BroadcastSidecarType::Poster,
                relativePath: $this->paths->relativeFile($filename),
                absolutePath: $this->paths->broadcastFile($context->broadcast, $filename),
                content: '',
                mediaItemId: (string) $stashItem->mediaItemId,
            );
        }

        return null;
    }

    private function publishPosterSidecar(BroadcastPlannedSidecar $sidecar, string $root): void
    {
        if ($sidecar->mediaItemId === null) {
            return;
        }

        $thumbnail = $this->assets->findByMediaItemAndRole(
            MediaItemId::parse($sidecar->mediaItemId),
            AssetRole::SourceThumbnail,
        );

        if ($thumbnail?->path === null || ! is_file($thumbnail->path)) {
            return;
        }

        try {
            $this->hardlinks->publishHardlink($thumbnail->path, $sidecar->absolutePath, $root);
        } catch (\App\Broadcasts\BroadcastException) {
            // Poster is optional — skip when hardlink unavailable.
        }
    }

    private function publishSubtitleSidecar(BroadcastPlannedSidecar $sidecar, string $root): bool
    {
        if ($sidecar->mediaItemId === null) {
            return false;
        }

        $subtitle = $this->readySubtitle(MediaItemId::parse($sidecar->mediaItemId));

        if ($subtitle?->path === null) {
            return false;
        }

        $this->hardlinks->publishHardlink($subtitle->path, $sidecar->absolutePath, $root);

        return true;
    }

    private function dispatchMissingCaptions(BroadcastContext $context, BroadcastPlan $plan): void
    {
        $mode = $this->captionMode($context);

        if ($mode === 'off') {
            return;
        }

        $languages = $context->settings()['caption_languages'] ?? 'en';
        $languages = is_string($languages) && trim($languages) !== '' ? trim($languages) : 'en';

        foreach ($plan->files as $planned) {
            $mediaItemId = MediaItemId::parse($planned->mediaItemId);

            if ($this->readySubtitle($mediaItemId) !== null) {
                continue;
            }

            $this->dispatch->dispatch(CommandType::AssetDownloadCaptions, [
                'media_item_id' => $planned->mediaItemId,
                'languages' => $languages,
                'include_auto' => $mode === 'creator_or_auto',
            ]);
        }
    }

    private function captionMode(BroadcastContext $context): string
    {
        $mode = $context->settings()['captions'] ?? 'off';

        return is_string($mode) && in_array($mode, ['creator_only', 'creator_or_auto'], true)
            ? $mode
            : 'off';
    }

    private function readySubtitle(MediaItemId $mediaItemId): ?\App\Vault\AssetRecord
    {
        $subtitle = $this->assets->findByMediaItemAndRole($mediaItemId, AssetRole::Subtitle);

        return $subtitle?->state === AssetState::Ready
            && $subtitle->path !== null
            && is_file($subtitle->path)
            ? $subtitle
            : null;
    }

    private function upsertPublishedAsset(
        string $broadcastId,
        string $broadcastItemId,
        string $mediaItemId,
        string $sourceAssetId,
        string $path,
        string $relativePath,
        string $sourcePath,
        AssetRole $role,
    ): void {
        $existing = \App\Vault\AssetRecord::select()
            ->where('broadcastItemId', $broadcastItemId)
            ->where('role', $role)
            ->first();

        $sizeBytes = is_file($sourcePath) ? filesize($sourcePath) : null;

        if ($existing === null) {
            $asset = $this->assets->create(
                mediaItemId: MediaItemId::parse($mediaItemId),
                role: $role,
                kind: AssetKind::Video,
                state: AssetState::Ready,
                path: $path,
                relativePath: $relativePath,
                sizeBytes: is_int($sizeBytes) ? $sizeBytes : null,
            );
            $asset->broadcastId = BroadcastId::parse($broadcastId);
            $asset->broadcastItemId = BroadcastItemId::parse($broadcastItemId);
            $asset->derivedFromAssetId = AssetId::parse($sourceAssetId);
            $this->assets->save($asset);

            return;
        }

        $existing->path = $path;
        $existing->relativePath = $relativePath;
        $existing->derivedFromAssetId = AssetId::parse($sourceAssetId);
        $existing->sizeBytes = is_int($sizeBytes) ? $sizeBytes : $existing->sizeBytes;
        $existing->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $existing->missingAt = null;
        $existing->missingReason = null;

        if ($existing->state !== AssetState::Ready) {
            $this->transitions->transitionAsset($existing, AssetState::Ready);
        } else {
            $this->assets->save($existing);
        }
    }

    private function markItemStale(\App\Broadcasts\BroadcastItemRecord $item, string $reason): void
    {
        $item->lastError = $reason;

        if ($item->state !== BroadcastItemState::Stale) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Stale);
        } else {
            $this->broadcastItems->save($item);
        }
    }

    private function pruneEmptyDirectories(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if (! $entry->isDir()) {
                continue;
            }

            $path = $entry->getPathname();

            if ($path === $root) {
                continue;
            }

            $contents = scandir($path);

            if ($contents !== false && count($contents) === 2) {
                @rmdir($path);
            }
        }
    }
}

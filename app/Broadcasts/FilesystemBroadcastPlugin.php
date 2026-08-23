<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\DownloadPolicy;
use App\Stashes\StashItemId;
use App\System\State\StateTransitionService;
use App\Vault\MediaItemId;

/** Core-owned hardlink view; it has no provider dependency. */
#[StashdBroadcast('Filesystem', 'Hardlinked filesystem broadcast.')]
final readonly class FilesystemBroadcastPlugin implements BroadcastPlugin, BroadcastPluginPolicy
{
    public function __construct(
        private BroadcastPathBuilder $paths,
        private BroadcastItemRepository $items,
        private HardlinkPublisher $hardlinks,
        private StateTransitionService $transitions,
    ) {}

    public function broadcastKeys(): array
    {
        return ['filesystem'];
    }

    public function supportedFileKinds(): array
    {
        return [FileKind::Audio, FileKind::Video];
    }

    public function uiControls(): array
    {
        return [];
    }

    public function supportsItemRebuild(): bool
    {
        return true;
    }

    public function acceptsDownloadPolicy(BroadcastRecord $broadcast, DownloadPolicy $policy): bool
    {
        return $policy !== DownloadPolicy::MetadataOnly;
    }

    public function derivedWorkCount(BroadcastContext $context): int
    {
        return 0;
    }

    public function prunesAfterPublish(): bool
    {
        return true;
    }

    public function plan(BroadcastContext $context): BroadcastPlan
    {
        $files = [];
        $skipped = [];
        $position = 0;

        foreach ($context->stashItems as $stashItem) {
            $asset = $context->vaultOriginals[(string) $stashItem->mediaItemId] ?? null;
            $media = $context->mediaItems[(string) $stashItem->mediaItemId] ?? null;

            if ($asset === null || $asset->path === null || $media === null || ! is_file($asset->path)) {
                $skipped[] = (string) $stashItem->id;

                continue;
            }
            $position++;
            $extension = pathinfo($asset->path, PATHINFO_EXTENSION) ?: 'bin';
            $filename = sprintf('%02d - %s.%s', $position, self::segment($media->title), $extension);
            $relative = $this->paths->relativeFile('Season 01', $filename);
            $files[] = new BroadcastPlannedFile(
                stashItemId: (string) $stashItem->id,
                mediaItemId: (string) $stashItem->mediaItemId,
                sourceAssetId: (string) $asset->id,
                sourcePath: $asset->path,
                relativePath: $relative,
                absolutePath: $this->paths->broadcastFile($context->broadcast, 'Season 01', $filename),
                filename: $filename,
            );
        }

        return new BroadcastPlan((string) $context->broadcast->id, $this->paths->broadcastRoot($context->broadcast), $files, skippedStashItemIds: $skipped, estimatedCopyBytes: array_sum(array_map(static fn(BroadcastPlannedFile $file): int => filesize($file->sourcePath) ?: 0, $files)));
    }

    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult
    {
        $root = $this->paths->claimRoot($context->broadcast);
        $published = [];

        foreach ($plan->files as $planned) {
            $item = $this->items->findByBroadcastAndStashItem(BroadcastId::parse($plan->broadcastId), StashItemId::parse($planned->stashItemId))
                ?? $this->items->create(BroadcastId::parse($plan->broadcastId), StashItemId::parse($planned->stashItemId), MediaItemId::parse($planned->mediaItemId));
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
            $this->hardlinks->publishHardlink($planned->sourcePath, $planned->absolutePath, $root);
            $item->publishedPath = $planned->absolutePath;
            $item->publishedUri = null;
            $item->lastError = null;
            $this->items->save($item);
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
            $published[] = $planned->absolutePath;
        }

        return new BroadcastPublishResult(count($published), 0, $published);
    }

    public function verify(BroadcastContext $context): BroadcastVerifyResult
    {
        $valid = [];
        $missing = [];

        foreach ($this->items->listForBroadcast(BroadcastId::parse((string) $context->broadcast->id)) as $item) {
            $path = $item->publishedPath;
            $source = $context->vaultOriginals[(string) $item->mediaItemId]?->path;

            if (is_string($path) && is_string($source) && $this->hardlinks->verifyHardlink($source, $path)) {
                $valid[] = (string) $item->id;
            } else {
                $missing[] = (string) $item->id;
            }
        }

        return new BroadcastVerifyResult($missing === [], count($valid), count($missing), $valid, [], $missing);
    }

    public function prune(BroadcastContext $context): BroadcastPruneResult
    {
        $root = $this->paths->assertOwnsRoot($context->broadcast);
        $expected = array_fill_keys(array_map(static fn(BroadcastPlannedFile $file): string => $file->absolutePath, $this->plan($context)->files), true);
        /** @var list<string> $removed */
        $removed = [];

        if (! is_dir($root)) {
            return new BroadcastPruneResult(0, []);
        }

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && $entry->getFilename() !== '.stashd-broadcast' && ! isset($expected[$entry->getPathname()])) {
                unlink($entry->getPathname());
                $removed[] = $entry->getPathname();
            }
        }

        return new BroadcastPruneResult(count($removed), $removed);
    }

    private static function segment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $value) ?: 'item';

        return trim($value, ' .-') ?: 'item';
    }
}

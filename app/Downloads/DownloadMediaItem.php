<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Config\StashdConfig;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationOutcome;
use App\Fixity\VaultChecksum;
use App\Providers\StashdUri;
use App\Stashes\StashId;
use App\Stashes\StashInputRepository;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\Support\DurationSeconds;
use App\Support\PrefixedUlid;
use App\System\State\StateTransitionService;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\System\Storage\StorageRootService;
use App\Vault\AssetId;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemState;
use App\Vault\MoveFileIntoVault;
use App\Vault\StageDownloadFiles;
use App\Vault\VaultPathBuilder;
use InvalidArgumentException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Support\Filesystem;

final readonly class DownloadMediaItem
{
    public function __construct(
        private DownloaderInterface $downloader,
        private DownloadPolicyEvaluator $policy,
        private MediaItemRepository $mediaItems,
        private StashRepository $stashes,
        private StashItemRepository $stashItems,
        private StashInputRepository $stashInputs,
        private AssetRepository $assets,
        private StorageLocationRepository $storageLocations,
        private StorageRootService $storageRoots,
        private StageDownloadFiles $tempStaging,
        private VaultPathBuilder $vaultPaths,
        private MoveFileIntoVault $fileMover,
        private StateTransitionService $transitions,
        private StashdConfig $config,
        private PreservationEventRepository $preservationEvents,
    ) {}

    public function execute(
        MediaItemId $mediaItemId,
        StashId $stashId,
        PrefixedUlid $jobId,
        bool $force = false,
        ?callable $onProgress = null,
    ): DownloadExecutionResult {
        $mediaItem = $this->mediaItems->find($mediaItemId)
            ?? throw DownloadException::withCode('media_item_not_found', 'Media item not found.');

        $stash = $this->stashes->find($stashId)
            ?? throw DownloadException::withCode('stash_not_found', 'Stash not found.');

        $stashItem = $this->stashItems->findByStashAndMediaItem($stashId, $mediaItemId);

        if ($stashItem === null) {
            throw DownloadException::withCode('stash_item_not_found', 'Media item is not part of the requested stash.');
        }

        $stashInput = $stashItem->stashInputId !== null ? $this->stashInputs->find($stashItem->stashInputId) : null;
        $providerOptions = $stashInput === null || $stashInput->options === null
            ? []
            : $stashInput->options->provider;

        $warnings = $this->policy->warningsForExplicitDownload($stash->downloadPolicy);
        $this->policy->assertExplicitDownloadAllowed($stash->downloadPolicy);
        $this->assertStorageReady();

        $existingOriginal = $this->assets->findByMediaItemAndRole($mediaItemId, AssetRole::VaultOriginal);
        $preserveExisting = $force
            && $mediaItem->state === MediaItemState::Ready
            && $existingOriginal?->state === AssetState::Ready
            && $existingOriginal->path !== null
            && Filesystem\is_file($existingOriginal->path);
        $originalAssets = $preserveExisting
            ? array_map(static fn(AssetRecord $asset): array => ['asset' => $asset, 'snapshot' => clone $asset], array_values(array_filter(
                $this->assets->listForMediaItem($mediaItemId),
                static fn(AssetRecord $asset): bool => $asset->state === AssetState::Ready,
            )))
            : [];

        if (! $force && $existingOriginal !== null && $existingOriginal->state === AssetState::Ready) {
            if ($existingOriginal->path !== null && Filesystem\is_file($existingOriginal->path)) {
                $this->ensureMediaItemReady($mediaItem);

                return new DownloadExecutionResult(
                    mediaItemId: $mediaItemId->toString(),
                    stashId: $stashId->toString(),
                    skipped: true,
                    assetsReady: count($this->assets->listForMediaItem($mediaItemId)),
                    warnings: $warnings,
                );
            }
        }

        $tempDirectory = $this->tempStaging->createWorkDirectory($jobId);
        $pendingAssets = [];

        try {
            $this->prepareMediaItemForDownload($mediaItem, $force);
            $request = new DownloadRequest(
                mediaItemId: $mediaItemId,
                stashId: $stashId,
                providerKey: $mediaItem->providerKey,
                providerItemId: $mediaItem->providerItemId,
                canonicalUri: StashdUri::parse($mediaItem->canonicalUri),
                downloadPolicy: $stash->downloadPolicy,
                tempDirectory: $tempDirectory,
                force: $force,
                durationSeconds: DurationSeconds::toSeconds($mediaItem->durationSeconds),
                thumbnailUri: $mediaItem->thumbnailUri !== null ? StashdUri::parse($mediaItem->thumbnailUri) : null,
                title: $mediaItem->title,
                publishedAt: $mediaItem->publishedAt,
                providerOptions: $providerOptions,
            );

            $download = $this->downloader->download($request, $onProgress);
            $this->assertDownloadOutputsComplete($download);

            foreach ($download->files as $file) {
                $pendingAssets[] = $this->createProcessingAsset($mediaItemId, $file, $force);
            }

            $ingested = $this->ingestAllFiles($mediaItem, $download, $pendingAssets, $force, (string) $jobId);
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Ready);
            $this->tempStaging->cleanupSuccess($tempDirectory);

            return new DownloadExecutionResult(
                mediaItemId: $mediaItemId->toString(),
                stashId: $stashId->toString(),
                skipped: false,
                assetsReady: $ingested,
                warnings: $warnings,
            );
        } catch (\Throwable $throwable) {
            $this->tempStaging->markFailed($tempDirectory);

            if ($preserveExisting) {
                $this->restoreAssets($originalAssets);
                $this->restoreMediaItem($mediaItem);
            } else {
                $this->markAssetsFailed($pendingAssets);
                $this->failMediaItem($mediaItem);
            }

            if ($throwable instanceof DownloadException) {
                throw $throwable;
            }

            if ($throwable instanceof InvalidArgumentException) {
                throw DownloadException::withCode('invalid_vault_path', $throwable->getMessage(), $throwable);
            }

            throw DownloadException::withCode('download_failed', $throwable->getMessage(), $throwable);
        }
    }

    private function assertStorageReady(): void
    {
        $this->storageRoots->ensureDirectories();

        foreach ([StorageLocationKey::Vault, StorageLocationKey::Temp] as $key) {
            $location = $this->storageLocations->findByKey($key);

            if ($location !== null && in_array($location->state, [StorageLocationState::Unavailable, StorageLocationState::Missing, StorageLocationState::Unwritable], true)) {
                throw DownloadException::withCode(
                    'storage_unavailable',
                    sprintf('Storage root %s is not writable.', $key->value),
                );
            }
        }

        foreach ([$this->config->vaultPath(), $this->config->tempPath()] as $path) {
            if (! Filesystem\is_directory($path) || ! Filesystem\is_writable($path)) {
                throw DownloadException::withCode(
                    'storage_unavailable',
                    sprintf('Storage path is not writable: %s', $path),
                );
            }
        }
    }

    private function ensureMediaItemReady(MediaItemRecord $mediaItem): void
    {
        if ($mediaItem->state !== MediaItemState::Ready) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Ready);
        }
    }

    private function assertDownloadOutputsComplete(DownloadResult $download): void
    {
        foreach ($download->files as $file) {
            if (! Filesystem\is_file($file->tempPath) || ! Filesystem\is_readable($file->tempPath)) {
                throw DownloadException::withCode(
                    'download_missing_output',
                    'Downloaded file is missing or unreadable before Vault ingest.',
                );
            }
        }
    }

    private function prepareMediaItemForDownload(MediaItemRecord $mediaItem, bool $force = false): void
    {
        if ($force && $mediaItem->state === MediaItemState::Ready) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::DownloadPending);
        }

        if ($mediaItem->state === MediaItemState::Discovered) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::MetadataReady);
            $mediaItem->metadataCapturedAt ??= DateTime::now(Timezone::UTC);
            $this->mediaItems->save($mediaItem);
        }

        if ($mediaItem->state === MediaItemState::MetadataReady) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::DownloadPending);
        }

        if ($mediaItem->state === MediaItemState::DownloadPending) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Downloading);
        }

        if ($mediaItem->state === MediaItemState::Missing || $mediaItem->state === MediaItemState::Failed) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::DownloadPending);
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Downloading);
        }

        if ($mediaItem->state !== MediaItemState::Downloading) {
            throw DownloadException::withCode(
                'invalid_media_item_state',
                'Media item cannot start download from state: ' . $mediaItem->state->value,
            );
        }
    }

    private function createProcessingAsset(MediaItemId $mediaItemId, DownloadedFile $file, bool $replaceExisting = false): AssetRecord
    {
        $existing = $this->assets->findByMediaItemAndRole($mediaItemId, $file->role);

        if ($existing !== null) {
            if ($existing->state === AssetState::Ready && ! $replaceExisting) {
                throw DownloadException::withCode(
                    'asset_already_ready',
                    'Refusing to overwrite ready Vault asset.',
                );
            }

            if ($existing->state !== AssetState::Processing) {
                $this->transitions->transitionAsset($existing, AssetState::Processing);
            }

            return $existing;
        }

        $asset = $this->assets->create(
            mediaItemId: $mediaItemId,
            role: $file->role,
            kind: $file->kind,
            state: AssetState::Pending,
            mimeType: $file->mimeType,
            container: $file->container,
            durationSeconds: $file->durationSeconds,
        );

        return $this->transitions->transitionAsset($asset, AssetState::Processing);
    }

    /**
     * @param  list<AssetRecord>  $pendingAssets
     */
    private function ingestAllFiles(
        MediaItemRecord $mediaItem,
        DownloadResult $download,
        array $pendingAssets,
        bool $replaceExisting = false,
        ?string $jobId = null,
    ): int {
        /** @var list<array{asset: AssetRecord, file: DownloadedFile, destination: string, checksum: ?string, sizeBytes: ?int}> $planned */
        $planned = [];
        $movedDestinations = [];
        $backups = [];

        try {
            foreach ($download->files as $index => $file) {
                $asset = $pendingAssets[$index];
                $destination = $this->vaultPaths->vaultFile(
                    $mediaItem->providerKey,
                    $mediaItem->providerItemId,
                    $file->filename,
                );

                $sizeBytes = $file->sizeBytes ?? filesize($file->tempPath);
                $checksum = VaultChecksum::computeFile($file->tempPath);

                if ($replaceExisting && $asset->path !== null && $asset->path !== $destination && Filesystem\is_file($asset->path)) {
                    $backup = $asset->path . '.refetch-' . bin2hex(random_bytes(6));

                    if (! rename($asset->path, $backup)) {
                        throw new \RuntimeException("Unable to stage existing Vault file for replacement: {$asset->path}");
                    }

                    $backups[$asset->path] = $backup;
                }

                if ($replaceExisting && Filesystem\is_file($destination)) {
                    $backup = $destination . '.refetch-' . bin2hex(random_bytes(6));

                    if (! rename($destination, $backup)) {
                        throw new \RuntimeException("Unable to stage existing Vault file for replacement: {$destination}");
                    }

                    $backups[$destination] = $backup;
                }

                $this->fileMover->moveIntoPlace($file->tempPath, $destination);
                $movedDestinations[] = $destination;

                $planned[] = [
                    'asset' => $asset,
                    'file' => $file,
                    'destination' => $destination,
                    'checksum' => $checksum,
                    'sizeBytes' => is_int($sizeBytes) ? $sizeBytes : null,
                ];
            }

            foreach ($planned as $entry) {
                $this->finalizeAsset($mediaItem, $entry['asset'], $entry['file'], $entry['destination'], $download, $entry['checksum'], $entry['sizeBytes'], $jobId);
            }

            foreach ($backups as $backup) {
                if (Filesystem\is_file($backup)) {
                    Filesystem\delete_file($backup);
                }
            }
        } catch (\Throwable $throwable) {
            $this->rollbackVaultFiles($movedDestinations);
            $this->restoreVaultFiles($backups);

            throw $throwable;
        }

        return count($planned);
    }

    /** @param list<string> $paths */
    private function rollbackVaultFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (Filesystem\is_file($path)) {
                Filesystem\delete_file($path);
            }
        }
    }

    /** @param array<string, string> $backups */
    private function restoreVaultFiles(array $backups): void
    {
        foreach ($backups as $destination => $backup) {
            if (Filesystem\is_file($destination)) {
                Filesystem\delete_file($destination);
            }

            if (Filesystem\is_file($backup)) {
                rename($backup, $destination);
            }
        }
    }

    /** @param list<array{asset: AssetRecord, snapshot: AssetRecord}> $originalAssets */
    private function restoreAssets(array $originalAssets): void
    {
        foreach ($originalAssets as $entry) {
            $asset = $entry['asset'];
            $snapshot = $entry['snapshot'];

            foreach (get_object_vars($snapshot) as $property => $value) {
                $asset->{$property} = $value;
            }

            $this->assets->save($asset);
        }
    }

    private function restoreMediaItem(MediaItemRecord $mediaItem): void
    {
        if ($mediaItem->state === MediaItemState::Downloading) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Ready);
        }
    }

    private function finalizeAsset(
        MediaItemRecord $mediaItem,
        AssetRecord $asset,
        DownloadedFile $file,
        string $destination,
        DownloadResult $download,
        ?string $checksum,
        ?int $sizeBytes,
        ?string $jobId,
    ): void {
        $asset->path = $destination;
        $asset->relativePath = $this->vaultPaths->relativeFile(
            $mediaItem->providerKey,
            $mediaItem->providerItemId,
            $file->filename,
        );
        $asset->mimeType = $file->mimeType;
        $asset->container = $file->container;
        $asset->sizeBytes = $sizeBytes;
        $asset->checksum = $checksum;
        $asset->durationSeconds = DurationSeconds::toDuration($file->durationSeconds);
        $asset->lastVerifiedAt = null;
        $asset->missingAt = null;
        $asset->missingReason = null;
        $this->assets->save($asset);
        $this->transitions->transitionAsset($asset, AssetState::Ready);
        $this->preservationEvents->create(
            assetId: AssetId::fromPrimaryKey($asset->id),
            eventType: PreservationEventType::FixityGenerated,
            outcome: PreservationOutcome::Success,
            expectedChecksum: $checksum,
            observedChecksum: $checksum,
            jobId: $jobId,
            detail: ['source' => 'staged_download'],
        );

        if ($file->role === AssetRole::SourceJson) {
            $mediaItem->metadataCapturedAt = $download->attemptedAt;
            $this->mediaItems->save($mediaItem);
        }
    }

    /** @param list<AssetRecord> $assets */
    private function markAssetsFailed(array $assets): void
    {
        foreach ($assets as $asset) {
            if ($asset->state === AssetState::Processing || $asset->state === AssetState::Pending) {
                $this->transitions->transitionAsset($asset, AssetState::Failed);
            }
        }
    }

    private function failMediaItem(MediaItemRecord $mediaItem): void
    {
        if ($mediaItem->state === MediaItemState::Downloading) {
            $this->transitions->transitionMediaItem($mediaItem, MediaItemState::Failed);
        }
    }
}

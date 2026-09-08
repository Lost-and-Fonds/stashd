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
use App\Vault\ItemId;
use App\Vault\ItemRecord;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\Vault\MoveFileIntoVault;
use App\Vault\StageDownloadFiles;
use App\Vault\VaultPathBuilder;
use InvalidArgumentException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Support\Filesystem;

final readonly class DownloadItem
{
    public function __construct(
        private DownloaderInterface $downloader,
        private DownloadPolicyEvaluator $policy,
        private ItemRepository $items,
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
        ItemId $itemId,
        StashId $stashId,
        PrefixedUlid $jobId,
        bool $force = false,
        ?callable $onProgress = null,
    ): DownloadExecutionResult {
        $item = $this->items->find($itemId)
            ?? throw DownloadException::withCode('item_not_found', 'Item not found.');

        $stash = $this->stashes->find($stashId)
            ?? throw DownloadException::withCode('stash_not_found', 'Stash not found.');

        $stashItem = $this->stashItems->findByStashAndItem($stashId, $itemId);

        if ($stashItem === null) {
            throw DownloadException::withCode('stash_item_not_found', 'Item is not part of the requested stash.');
        }

        $stashInput = $stashItem->stashInputId !== null ? $this->stashInputs->find($stashItem->stashInputId) : null;
        $providerOptions = $stashInput === null || $stashInput->options === null
            ? []
            : $stashInput->options->provider;

        $warnings = $this->policy->warningsForExplicitDownload($stash->downloadPolicy);
        $this->policy->assertExplicitDownloadAllowed($stash->downloadPolicy);
        $this->assertStorageReady();

        $existingOriginal = $this->assets->findByItemAndRole($itemId, AssetRole::VaultOriginal);
        $preserveExisting = $force
            && $item->state === ItemState::Ready
            && $existingOriginal?->state === AssetState::Ready
            && $existingOriginal->path !== null
            && Filesystem\is_file($existingOriginal->path);
        $originalAssets = $preserveExisting
            ? array_map(static fn(AssetRecord $asset): array => ['asset' => $asset, 'snapshot' => clone $asset], array_values(array_filter(
                $this->assets->listForItem($itemId),
                static fn(AssetRecord $asset): bool => $asset->state === AssetState::Ready,
            )))
            : [];

        if (! $force && $existingOriginal !== null && $existingOriginal->state === AssetState::Ready) {
            if ($existingOriginal->path !== null && Filesystem\is_file($existingOriginal->path)) {
                $this->ensureItemReady($item);

                return new DownloadExecutionResult(
                    itemId: $itemId->toString(),
                    stashId: $stashId->toString(),
                    skipped: true,
                    assetsReady: count($this->assets->listForItem($itemId)),
                    warnings: $warnings,
                );
            }
        }

        $tempDirectory = $this->tempStaging->createWorkDirectory($jobId);
        $pendingAssets = [];

        try {
            $this->prepareItemForDownload($item, $force);
            $request = new DownloadRequest(
                itemId: $itemId,
                stashId: $stashId,
                providerKey: $item->providerKey,
                providerItemId: $item->providerItemId,
                canonicalUri: StashdUri::parse($item->canonicalUri),
                downloadPolicy: $stash->downloadPolicy,
                tempDirectory: $tempDirectory,
                force: $force,
                durationSeconds: DurationSeconds::toSeconds($item->duration),
                thumbnailUri: $item->thumbnailUri !== null ? StashdUri::parse($item->thumbnailUri) : null,
                title: $item->title,
                publishedAt: $item->publishedAt,
                providerOptions: $providerOptions,
            );

            $download = $this->downloader->download($request, $onProgress);
            $this->assertDownloadOutputsComplete($download);

            foreach ($download->files as $file) {
                $pendingAssets[] = $this->createProcessingAsset($itemId, $file, $force);
            }

            $ingested = $this->ingestAllFiles($item, $download, $pendingAssets, $force, (string) $jobId);
            $this->transitions->transitionItem($item, ItemState::Ready);
            $this->tempStaging->cleanupSuccess($tempDirectory);

            return new DownloadExecutionResult(
                itemId: $itemId->toString(),
                stashId: $stashId->toString(),
                skipped: false,
                assetsReady: $ingested,
                warnings: $warnings,
            );
        } catch (\Throwable $throwable) {
            $this->tempStaging->markFailed($tempDirectory);

            if ($preserveExisting) {
                $this->restoreAssets($originalAssets);
                $this->restoreItem($item);
            } else {
                $this->markAssetsFailed($pendingAssets);
                $this->failItem($item);
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

    private function ensureItemReady(ItemRecord $item): void
    {
        if ($item->state !== ItemState::Ready) {
            $this->transitions->transitionItem($item, ItemState::Ready);
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

    private function prepareItemForDownload(ItemRecord $item, bool $force = false): void
    {
        if ($force && $item->state === ItemState::Ready) {
            $this->transitions->transitionItem($item, ItemState::DownloadPending);
        }

        if ($item->state === ItemState::Discovered) {
            $this->transitions->transitionItem($item, ItemState::MetadataReady);
            $item->metadataCapturedAt ??= DateTime::now(Timezone::UTC);
            $this->items->save($item);
        }

        if ($item->state === ItemState::MetadataReady) {
            $this->transitions->transitionItem($item, ItemState::DownloadPending);
        }

        if ($item->state === ItemState::DownloadPending) {
            $this->transitions->transitionItem($item, ItemState::Downloading);
        }

        if ($item->state === ItemState::Missing || $item->state === ItemState::Failed) {
            $this->transitions->transitionItem($item, ItemState::DownloadPending);
            $this->transitions->transitionItem($item, ItemState::Downloading);
        }

        if ($item->state !== ItemState::Downloading) {
            throw DownloadException::withCode(
                'invalid_item_state',
                'Item cannot start download from state: ' . $item->state->value,
            );
        }
    }

    private function createProcessingAsset(ItemId $itemId, DownloadedFile $file, bool $replaceExisting = false): AssetRecord
    {
        $existing = $this->assets->findByItemAndRole($itemId, $file->role);

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
            itemId: $itemId,
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
        ItemRecord $item,
        DownloadResult $download,
        array $pendingAssets,
        bool $replaceExisting = false,
        ?string $jobId = null,
    ): int {
        /** @var list<array{asset: AssetRecord, file: DownloadedFile, destination: string, checksum: string, sizeBytes: ?int}> $planned */
        $planned = [];
        $movedDestinations = [];
        $backups = [];

        try {
            foreach ($download->files as $index => $file) {
                $asset = $pendingAssets[$index];
                $destination = $this->vaultPaths->vaultFile(
                    $item->providerKey,
                    $item->providerItemId,
                    $file->filename,
                );

                $sizeBytes = $file->sizeBytes ?? filesize($file->tempPath);

                try {
                    $checksum = VaultChecksum::requiredFile($file->tempPath);
                } catch (\RuntimeException $exception) {
                    throw DownloadException::withCode(
                        'checksum_failed',
                        'Unable to compute the required SHA-256 checksum before Vault ingest.',
                        $exception,
                    );
                }

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
                $this->finalizeAsset($item, $entry['asset'], $entry['file'], $entry['destination'], $download, $entry['checksum'], $entry['sizeBytes'], $jobId);
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

    private function restoreItem(ItemRecord $item): void
    {
        if ($item->state === ItemState::Downloading) {
            $this->transitions->transitionItem($item, ItemState::Ready);
        }
    }

    private function finalizeAsset(
        ItemRecord $item,
        AssetRecord $asset,
        DownloadedFile $file,
        string $destination,
        DownloadResult $download,
        string $checksum,
        ?int $sizeBytes,
        ?string $jobId,
    ): void {
        $asset->path = $destination;
        $asset->relativePath = $this->vaultPaths->relativeFile(
            $item->providerKey,
            $item->providerItemId,
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
            $item->metadataCapturedAt = $download->attemptedAt;
            $this->items->save($item);
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

    private function failItem(ItemRecord $item): void
    {
        if ($item->state === ItemState::Downloading) {
            $this->transitions->transitionItem($item, ItemState::Failed);
        }
    }
}

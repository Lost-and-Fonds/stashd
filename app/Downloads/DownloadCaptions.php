<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Plugins\ExternalInputPluginRegistry;
use App\Providers\StashdUri;
use App\Support\PrefixedUlid;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\MoveFileIntoVault;
use App\Vault\VaultPathBuilder;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class DownloadCaptions
{
    public function __construct(private ExternalInputPluginRegistry $plugins, private ItemRepository $items, private AssetRepository $assets, private VaultPathBuilder $paths, private MoveFileIntoVault $mover) {}

    public function execute(ItemId $itemId, PrefixedUlid $jobId, string $languages, bool $includeAuto): void
    {
        $item = $this->items->find($itemId) ?? throw DownloadException::withCode('item_not_found', 'Item not found.');
        $temp = sys_get_temp_dir() . '/stashd-captions-' . $jobId;

        try {
            Filesystem\create_directory($temp, 0o775);
        } catch (FilesystemException) {
            throw DownloadException::withCode('temp_not_writable', 'Could not create caption staging directory.');
        }

        $plugin = $this->plugins->findDownloader($item->providerKey);

        if ($plugin === null) {
            throw DownloadException::withCode('captions_unavailable', 'No Input plugin is available for this item.');
        }

        $files = $plugin->acquireArtifacts(
            item: [
                'id' => $item->providerItemId,
                'provider_key' => $item->providerKey,
                'reference' => StashdUri::parse($item->canonicalUri)->toString(),
                'title' => $item->title,
                'description' => $item->description,
                'published_at' => $item->publishedAt?->toRfc3339(useZ: true),
                'artwork_reference' => $item->thumbnailUri,
                'duration_seconds' => $item->duration === null ? null : (int) $item->duration->getTotalSeconds(),
                'kind' => $item->contentType,
            ],
            staging: $temp,
            mediaKind: 'video',
            options: ['include_captions' => true, 'caption_languages' => $languages, 'include_auto' => $includeAuto],
        );
        $source = array_values(array_filter($files, static fn($file): bool => $file->role === AssetRole::Subtitle))[0] ?? null;

        if ($source === null) {
            throw DownloadException::withCode('captions_unavailable', 'No requested caption track is available.');
        }

        $language = explode('.', $source->filename)[1] ?? null;
        $asset = $this->assets->findByItemAndRole($itemId, AssetRole::Subtitle);

        if ($asset !== null && $asset->state === AssetState::Ready) {
            return;
        }
        $asset ??= $this->assets->create($itemId, AssetRole::Subtitle, AssetKind::Subtitle, language: $language);
        $destination = $this->paths->vaultFile($item->providerKey, $item->providerItemId, 'captions.' . ($language ?? 'und') . '.vtt');
        $this->mover->moveIntoPlace($source->tempPath, $destination);
        $asset->state = AssetState::Ready;
        $asset->path = $destination;
        $asset->relativePath = $this->paths->relativeFile($item->providerKey, $item->providerItemId, basename($destination));
        $asset->mimeType = 'text/vtt';
        $asset->container = 'vtt';
        $asset->sizeBytes = filesize($destination) ?: null;
        $asset->language = $language;
        $this->assets->save($asset);
    }
}

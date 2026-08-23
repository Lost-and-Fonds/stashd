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
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;
use App\Vault\MoveFileIntoVault;
use App\Vault\VaultPathBuilder;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class DownloadCaptions
{
    public function __construct(private ExternalInputPluginRegistry $plugins, private MediaItemRepository $mediaItems, private AssetRepository $assets, private VaultPathBuilder $paths, private MoveFileIntoVault $mover) {}

    public function execute(MediaItemId $mediaItemId, PrefixedUlid $jobId, string $languages, bool $includeAuto): void
    {
        $media = $this->mediaItems->find($mediaItemId) ?? throw DownloadException::withCode('media_item_not_found', 'Media item not found.');
        $temp = sys_get_temp_dir() . '/stashd-captions-' . $jobId;

        try {
            create_directory($temp, 0o775);
        } catch (FilesystemException) {
            throw DownloadException::withCode('temp_not_writable', 'Could not create caption staging directory.');
        }

        $plugin = $this->plugins->findDownloader($media->providerKey);

        if ($plugin === null) {
            throw DownloadException::withCode('captions_unavailable', 'No Input plugin is available for this media item.');
        }

        $files = $plugin->acquireArtifacts(
            item: [
                'id' => $media->providerItemId,
                'reference' => StashdUri::parse($media->canonicalUri)->toString(),
                'title' => $media->title,
                'description' => $media->description,
                'published_at' => $media->publishedAt?->toRfc3339(useZ: true),
                'artwork_reference' => $media->thumbnailUri,
                'duration_seconds' => $media->durationSeconds,
                'kind' => $media->contentType,
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
        $asset = $this->assets->findByMediaItemAndRole($mediaItemId, AssetRole::Subtitle);

        if ($asset !== null && $asset->state === AssetState::Ready) {
            return;
        }
        $asset ??= $this->assets->create($mediaItemId, AssetRole::Subtitle, AssetKind::Subtitle, language: $language);
        $destination = $this->paths->vaultFile($media->providerKey, $media->providerItemId, 'captions.' . ($language ?? 'und') . '.vtt');
        $this->mover->moveIntoPlace($source->tempPath, $destination);
        $asset->state = AssetState::Ready;
        $asset->path = $destination;
        $asset->relativePath = $this->paths->relativeFile($media->providerKey, $media->providerItemId, basename($destination));
        $asset->mimeType = 'text/vtt';
        $asset->container = 'vtt';
        $asset->sizeBytes = filesize($destination) ?: null;
        $asset->language = $language;
        $this->assets->save($asset);
    }
}

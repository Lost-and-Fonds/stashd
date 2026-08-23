<?php

declare(strict_types=1);

namespace App\Downloads\Fake;

use App\Downloads\DownloadedFile;
use App\Downloads\DownloaderInterface;
use App\Downloads\DownloadException;
use App\Downloads\DownloadProbeResult;
use App\Downloads\DownloadRequest;
use App\Downloads\DownloadResult;
use App\Stashes\DownloadPolicy;
use App\Vault\AssetKind;
use App\Vault\AssetRole;
use App\Vault\VaultSidecarBuilder;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Support\Filesystem\is_directory;
use function Tempest\Support\Filesystem\is_writable;
use function Tempest\Support\Filesystem\write_file;
use function Tempest\Support\str;

/**
 * Deterministic fake downloader for tests and local development.
 *
 * Writes tiny fixture files into the temp work directory — never touches Vault directly.
 */
final readonly class FakeDownloader implements DownloaderInterface
{
    public const string IMPLEMENTATION = 'fake';

    public function __construct(
        private VaultSidecarBuilder $sidecars,
    ) {}

    public function implementationName(): string
    {
        return self::IMPLEMENTATION;
    }

    public function implementationVersion(): ?string
    {
        return '4a.0';
    }

    public function probe(): DownloadProbeResult
    {
        return new DownloadProbeResult(
            available: true,
            implementation: self::IMPLEMENTATION,
            implementationVersion: $this->implementationVersion(),
        );
    }

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        if (! is_directory($request->tempDirectory) || ! is_writable($request->tempDirectory)) {
            throw DownloadException::withCode('temp_not_writable', 'Temp download directory is not writable.');
        }

        if ($request->downloadPolicy === DownloadPolicy::MetadataOnly) {
            throw DownloadException::withCode(
                'download_policy_metadata_only',
                'Metadata-only stashes do not download media.',
            );
        }

        $attemptedAt = DateTime::now(Timezone::UTC);

        $files = match ($request->downloadPolicy) {
            DownloadPolicy::AudioOnly => [$this->writeOriginal($request, 'original.fake-audio', AssetKind::Audio, 'audio/fake')],
            default => [$this->writeOriginal($request, 'original.fake', AssetKind::Video, 'application/x-stashd-fake')],
        };

        if ($request->thumbnailUri !== null) {
            $files[] = $this->writeThumbnail($request);
        }

        $files[] = $this->writeMetadata($request, $attemptedAt);
        $files[] = $this->writeSource($request, $attemptedAt);

        return new DownloadResult(
            files: $files,
            implementation: self::IMPLEMENTATION,
            implementationVersion: $this->implementationVersion(),
            sourceUri: $request->canonicalUri,
            attemptedAt: $attemptedAt,
        );
    }

    private function writeOriginal(DownloadRequest $request, string $filename, AssetKind $kind, string $mime): DownloadedFile
    {
        $path = $this->tempPath($request, $filename);
        $content = sprintf(
            "stashd-fake-media\nprovider=%s\nitem=%s\npolicy=%s\n",
            $request->providerKey,
            $request->providerItemId,
            $request->downloadPolicy->value,
        );
        write_file($path, $content);

        return new DownloadedFile(
            tempPath: $path,
            filename: $filename,
            role: AssetRole::VaultOriginal,
            kind: $kind,
            mimeType: $mime,
            container: str($filename)->afterLast('.')->toString(),
            sizeBytes: strlen($content),
            durationSeconds: $request->durationSeconds,
        );
    }

    private function writeThumbnail(DownloadRequest $request): DownloadedFile
    {
        $path = $this->tempPath($request, 'source-thumbnail.jpg');
        $content = "fake-jpeg\n{$request->providerItemId}\n";
        write_file($path, $content);

        return new DownloadedFile(
            tempPath: $path,
            filename: 'source-thumbnail.jpg',
            role: AssetRole::SourceThumbnail,
            kind: AssetKind::Image,
            mimeType: 'image/jpeg',
            container: 'jpg',
            sizeBytes: strlen($content),
        );
    }

    private function writeMetadata(DownloadRequest $request, DateTime $capturedAt): DownloadedFile
    {
        $path = $this->tempPath($request, 'metadata.json');
        $payload = $this->sidecars->metadata($request, $capturedAt);
        write_file($path, $payload);

        return new DownloadedFile(
            tempPath: $path,
            filename: 'metadata.json',
            role: AssetRole::MetadataJson,
            kind: AssetKind::Metadata,
            mimeType: 'application/json',
            container: 'json',
            sizeBytes: strlen($payload),
        );
    }

    private function writeSource(DownloadRequest $request, DateTime $attemptedAt): DownloadedFile
    {
        $path = $this->tempPath($request, 'source.json');
        $result = new DownloadResult(
            files: [],
            implementation: self::IMPLEMENTATION,
            implementationVersion: $this->implementationVersion(),
            sourceUri: $request->canonicalUri,
            attemptedAt: $attemptedAt,
        );
        $payload = $this->sidecars->sourceJson($request, $result);
        write_file($path, $payload);

        return new DownloadedFile(
            tempPath: $path,
            filename: 'source.json',
            role: AssetRole::SourceJson,
            kind: AssetKind::Metadata,
            mimeType: 'application/json',
            container: 'json',
            sizeBytes: strlen($payload),
        );
    }

    private function tempPath(DownloadRequest $request, string $filename): string
    {
        return rtrim($request->tempDirectory, '/') . '/' . $filename;
    }
}

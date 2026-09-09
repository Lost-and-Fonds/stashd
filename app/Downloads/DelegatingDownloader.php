<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Plugins\ExternalInputPluginRegistry;

/** Routes downloads to a registered Input plugin. */
final readonly class DelegatingDownloader implements DownloaderInterface
{
    public function __construct(
        private ?ExternalInputPluginRegistry $externalPlugins = null,
    ) {}

    public function implementationName(): string
    {
        return 'routing';
    }

    public function implementationVersion(): ?string
    {
        return null;
    }

    public function probe(): DownloadProbeResult
    {
        $downloaders = $this->externalPlugins?->downloaders() ?? [];
        $available = array_filter(
            $downloaders,
            static fn(DownloaderInterface $downloader): bool => $downloader->probe()->available,
        );

        return new DownloadProbeResult(
            available: $available !== [],
            implementation: $this->implementationName(),
            implementationVersion: null,
            message: $available === [] ? 'No downloader is available.' : null,
        );
    }

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $external = $this->externalPlugins?->findDownloader($request->providerKey);

        if ($external !== null) {
            return $external->download($request, $onProgress);
        }

        throw DownloadException::withCode(
            'download_provider_unavailable',
            "No external Input plugin is registered for provider $request->providerKey.",
        );
    }

    public function acquireAssets(array $item, string $staging, string $mediaKind, array $options = [], ?array $requestedRoles = null): AssetAcquisitionResult
    {
        $provider = is_string($item['provider_key'] ?? null) ? $item['provider_key'] : '';
        $external = $this->externalPlugins?->findDownloader($provider);

        if ($external !== null) {
            return $external->acquireAssets($item, $staging, $mediaKind, $options, $requestedRoles);
        }

        throw DownloadException::withCode('acquisition_provider_unavailable', "No external Input plugin is registered for provider $provider.");
    }

    public function acquireArtifacts(array $item, string $staging, string $mediaKind, array $options = []): array
    {
        return $this->acquireAssets($item, $staging, $mediaKind, $options)->files;
    }
}

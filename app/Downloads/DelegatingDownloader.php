<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Downloads\Fake\FakeDownloader;
use App\Plugins\ExternalInputPluginRegistry;

/** Routes downloads to a registered external Input plugin or the test fake. */
final readonly class DelegatingDownloader implements DownloaderInterface
{
    public function __construct(
        private FakeDownloader $fake,
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
        $fake = $this->fake->probe();
        $external = $this->externalPlugins?->providers() ?? [];

        return new DownloadProbeResult(
            available: $fake->available || $external !== [],
            implementation: $this->implementationName(),
            implementationVersion: null,
            message: $external === [] && ! $fake->available ? 'No downloader is available.' : null,
        );
    }

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $external = $this->externalPlugins?->findDownloader($request->providerKey);
        if ($external !== null) {
            return $external->download($request, $onProgress);
        }

        if ($request->providerKey === 'fake') {
            return $this->fake->download($request, $onProgress);
        }

        throw DownloadException::withCode(
            'download_provider_unavailable',
            "No external Input plugin is registered for provider {$request->providerKey}.",
        );
    }
}

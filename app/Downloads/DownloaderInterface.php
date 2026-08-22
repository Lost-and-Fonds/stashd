<?php

declare(strict_types=1);

namespace App\Downloads;

/**
 * Download service boundary — all media acquisition must go through this interface.
 *
 * The fake implementation remains for deterministic tests; real providers
 * acquire through registered external Input plugins.
 */
interface DownloaderInterface
{
    public function implementationName(): string;

    public function implementationVersion(): ?string;

    public function probe(): DownloadProbeResult;

    /**
     * @param  ?callable  $onProgress  Invoked with implementation progress where
     *                                 the implementation supports it.
     */
    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult;
}

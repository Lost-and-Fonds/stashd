<?php

declare(strict_types=1);

namespace App\Transcoding\Ffmpeg;

/**
 * Seam for ffmpeg -- Stashd must not spawn ffmpeg processes outside this boundary.
 */
interface FfmpegGateway
{
    public function probe(): FfmpegProbeResult;

    /**
     * @param ?callable(FfmpegProgress): void $onProgress
     */
    public function transcodeToMp3(
        string $sourcePath,
        string $destinationPath,
        FfmpegAudioProfile $profile,
        ?int $totalSeconds,
        ?callable $onProgress = null,
    ): FfmpegTranscodeResult;

}

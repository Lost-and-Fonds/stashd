<?php

declare(strict_types=1);

namespace App\Transcoding\Ffmpeg;

use Tempest\Process\ProcessResult;

/**
 * Deterministic ffmpeg stand-in for tests -- never spawns a real process.
 */
final class StubFfmpegGateway implements FfmpegGateway
{
    public int $transcodeCalls = 0;

    public bool $failNextTranscode = false;

    public function probe(): FfmpegProbeResult
    {
        return new FfmpegProbeResult(
            available: true,
            binary: 'stub-ffmpeg',
            version: 'stub-7.0',
        );
    }

    public function transcodeToMp3(
        string $sourcePath,
        string $destinationPath,
        FfmpegAudioProfile $profile,
        ?int $totalSeconds,
        ?callable $onProgress = null,
    ): FfmpegTranscodeResult {
        $this->transcodeCalls++;

        if ($onProgress !== null) {
            $onProgress(new FfmpegProgress(currentSeconds: 0.0, totalSeconds: $totalSeconds, percent: 0.0, etaSeconds: null));
            $onProgress(new FfmpegProgress(currentSeconds: (float) ($totalSeconds ?? 0) / 2, totalSeconds: $totalSeconds, percent: 50.0, etaSeconds: 1));
            $onProgress(new FfmpegProgress(currentSeconds: (float) ($totalSeconds ?? 0), totalSeconds: $totalSeconds, percent: 100.0, etaSeconds: 0));
        }

        if ($this->failNextTranscode) {
            $this->failNextTranscode = false;

            throw new FfmpegProcessFailedException(
                new ProcessResult(1, '', 'stub ffmpeg failed'),
                'stub ffmpeg failed',
            );
        }

        file_put_contents($destinationPath, "stub-ffmpeg-audio\nsource={$sourcePath}\n");

        return new FfmpegTranscodeResult(successful: true, exitCode: 0);
    }

}

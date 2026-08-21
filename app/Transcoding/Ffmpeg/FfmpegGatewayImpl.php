<?php

declare(strict_types=1);

namespace App\Transcoding\Ffmpeg;

use App\Config\FfmpegConfig;
use Tempest\DateTime\Duration;
use Tempest\Process\OutputChannel;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessExecutor;

final readonly class FfmpegGatewayImpl implements FfmpegGateway
{
    public function __construct(
        private FfmpegConfig $config,
        private ProcessExecutor $executor,
    ) {
    }

    public function probe(): FfmpegProbeResult
    {
        try {
            $result = $this->executor->run(new PendingProcess(
                command: [$this->config->binary, '-version'],
                timeout: Duration::seconds(10),
            ));

            if (! $result->successful()) {
                return new FfmpegProbeResult(
                    available: false,
                    binary: $this->config->binary,
                    message: $this->redact($result->errorOutput),
                );
            }

            $firstLine = strtok($result->output, "\n") ?: null;

            return new FfmpegProbeResult(
                available: true,
                binary: $this->config->binary,
                version: $firstLine !== null ? trim($firstLine) : null,
            );
        } catch (\Throwable $throwable) {
            return new FfmpegProbeResult(
                available: false,
                binary: $this->config->binary,
                message: $this->redact($throwable->getMessage()),
            );
        }
    }

    /**
     * @param ?callable(FfmpegProgress): void $onProgress
     */
    public function transcodeToMp3(
        string $sourcePath,
        string $destinationPath,
        FfmpegAudioProfile $profile,
        ?int $totalSeconds,
        ?callable $onProgress = null,
    ): FfmpegTranscodeResult {
        $pending = new PendingProcess(
            command: [
                $this->config->binary,
                '-y',
                '-loglevel', 'error',
                '-i', $sourcePath,
                '-vn',
                '-map_metadata', '0',
                '-map_chapters', '0',
                '-ac', (string) $profile->channels,
                '-ar', (string) $profile->sampleRateHz,
                '-b:a', $profile->bitrateKbps . 'k',
                '-id3v2_version', '3',
                '-progress', 'pipe:2',
                '-nostats',
                $destinationPath,
            ],
            timeout: Duration::seconds($this->config->timeoutSeconds),
        );

        $buffer = '';
        $startedAt = microtime(true);

        $invoked = $this->executor->start($pending);
        $result = $invoked->wait(function (OutputChannel $channel, string $bytes) use (&$buffer, $onProgress, $totalSeconds, $startedAt): void {
            if ($channel !== OutputChannel::ERROR || $onProgress === null) {
                return;
            }

            $buffer .= $bytes;
            $this->parseProgressBlocks($buffer, $onProgress, $totalSeconds, $startedAt);
        });

        if (! $result->successful()) {
            throw new FfmpegProcessFailedException(
                $result,
                $this->redact($result->errorOutput) ?: 'ffmpeg exited with status ' . $result->exitCode,
            );
        }

        return new FfmpegTranscodeResult(successful: true, exitCode: $result->exitCode);
    }

    /**
     * Consumes complete `key=value` lines from the start of `$buffer`, leaving
     * any trailing partial line for the next chunk. ffmpeg's `-progress`
     * output is line-buffered but process output callbacks deliver raw byte
     * chunks with no guaranteed line alignment.
     *
     * @param callable(FfmpegProgress): void $onProgress
     */
    private function parseProgressBlocks(string &$buffer, callable $onProgress, ?int $totalSeconds, float $startedAt): void
    {
        while (($newlinePos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newlinePos);
            $buffer = substr($buffer, $newlinePos + 1);

            // out_time_us is the unambiguous key; out_time_ms is a long-standing
            // ffmpeg naming quirk that is also actually microseconds, not
            // milliseconds -- both are handled identically here.
            if (! str_starts_with($line, 'out_time_us=') && ! str_starts_with($line, 'out_time_ms=')) {
                continue;
            }

            [, $value] = explode('=', $line, 2);
            $microseconds = (int) trim($value);

            if ($microseconds < 0) {
                continue;
            }

            $currentSeconds = $microseconds / 1_000_000;
            $percent = $totalSeconds !== null && $totalSeconds > 0
                ? min(100.0, round($currentSeconds / $totalSeconds * 100, 2))
                : 0.0;
            $elapsedSeconds = microtime(true) - $startedAt;
            $etaSeconds = $percent > 0.0 ? (int) round($elapsedSeconds * (100 - $percent) / $percent) : null;

            $onProgress(new FfmpegProgress(
                currentSeconds: $currentSeconds,
                totalSeconds: $totalSeconds,
                percent: $percent,
                etaSeconds: $etaSeconds,
            ));
        }
    }

    private function redact(string $message): string
    {
        $redacted = preg_replace(
            '/\b(?:Bearer\s+)?[A-Za-z0-9_\-]{32,}\b/',
            '[REDACTED]',
            $message,
        );

        return $redacted ?? $message;
    }
}

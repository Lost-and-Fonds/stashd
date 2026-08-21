<?php

declare(strict_types=1);

namespace App\Transcoding\Ffmpeg;

/** Encode parameters for an ffmpeg audio transcode. The v1 default is the only profile that exists. */
final readonly class FfmpegAudioProfile
{
    public function __construct(
        public string $codec,
        public int $bitrateKbps,
        public int $channels,
        public int $sampleRateHz,
    ) {
    }

    /** MP3, 128kbps, stereo -- the default audio representation profile. */
    public static function v1Default(): self
    {
        return new self(
            codec: 'mp3',
            bitrateKbps: 128,
            channels: 2,
            sampleRateHz: 44100,
        );
    }
}

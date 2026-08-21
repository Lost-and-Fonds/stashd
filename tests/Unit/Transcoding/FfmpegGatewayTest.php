<?php

declare(strict_types=1);

namespace Tests\Unit\Transcoding;

use App\Config\FfmpegConfig;
use App\Transcoding\Ffmpeg\FfmpegAudioProfile;
use App\Transcoding\Ffmpeg\FfmpegGatewayImpl;
use Tempest\Process\GenericProcessExecutor;

test('podcast audio transcodes explicitly preserve source chapters and metadata', function (): void {
    $binary = sys_get_temp_dir() . '/stashd-ffmpeg-args-' . bin2hex(random_bytes(4));
    $arguments = sys_get_temp_dir() . '/stashd-ffmpeg-args-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($arguments) . "\n");
    chmod($binary, 0700);

    try {
        $gateway = new FfmpegGatewayImpl(new FfmpegConfig(binary: $binary, timeoutSeconds: 30), new GenericProcessExecutor());

        $gateway->transcodeToMp3(
            sourcePath: '/tmp/source.mp4',
            destinationPath: '/tmp/output.mp3',
            profile: FfmpegAudioProfile::v1Default(),
            totalSeconds: 120,
        );

        $command = (string) file_get_contents($arguments);

        expect($command)->toContain("-map_metadata\n0")
            ->and($command)->toContain("-map_chapters\n0")
            ->and($command)->toContain("-id3v2_version\n3");
    } finally {
        @unlink($binary);
        @unlink($arguments);
    }
});

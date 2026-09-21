<?php

declare(strict_types=1);

use AppPlugins\PluginInputRuntime;
use App\Vault\AssetKind;
use App\Vault\AssetRole;

test('plugin acquisition language is mapped to downloaded files', function (): void {
    $staging = sys_get_temp_dir() . '/stashd-plugin-language-' . bin2hex(random_bytes(4));
    mkdir($staging, 0775, true);
    file_put_contents($staging . '/youtube-video.en.vtt', "WEBVTT\n");

    try {
        $runtime = (new ReflectionClass(PluginInputRuntime::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PluginInputRuntime::class, 'filesFromResult');
        $files = $method->invoke($runtime, [
            'artifacts' => [[
                'reference' => 'youtube-video.en.vtt',
                'media-type' => 'text/vtt',
                'role' => 'captions',
                'language' => 'en',
            ]],
        ], $staging);

        expect($files)->toHaveCount(1)
            ->and($files[0]->role)->toBe(AssetRole::Subtitle)
            ->and($files[0]->kind)->toBe(AssetKind::Subtitle)
            ->and($files[0]->mimeType)->toBe('text/vtt')
            ->and($files[0]->language)->toBe('en');
    } finally {
        unlink($staging . '/youtube-video.en.vtt');
        rmdir($staging);
    }
});

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHelperGrant;
use App\Plugins\PluginHostClient;

[$script, $socket, $component, $helper, $url, $expected] = array_pad($argv, 6, null);
if (! is_string($socket) || ! is_string($component) || ! is_string($helper) || ! is_string($url) || ! is_string($expected)) {
    fwrite(STDERR, "usage: plugin-acquire-error.php <socket> <component> <helper> <url> <expected-error>\n");
    exit(64);
}

$staging = sys_get_temp_dir() . '/stashd-youtube-acquire-error-' . bin2hex(random_bytes(6));
mkdir($staging, 0700, true);

try {
    (new PluginHostClient($socket))->acquireInput(
        $component,
        [
            'id' => 'fixture-error',
            'reference' => $url,
            'title' => 'Fixture Error',
        ],
        $staging,
        new PluginHelperGrant('yt-dlp', $helper),
    );
    fwrite(STDERR, 'expected acquisition error was not returned' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    if (! str_contains($exception->getMessage(), "[{$expected}]:")) {
        fwrite(STDERR, 'unexpected acquisition error: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    echo $expected . PHP_EOL;
} finally {
    foreach (glob($staging . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($staging);
}

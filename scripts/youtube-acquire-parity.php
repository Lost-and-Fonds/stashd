<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHelperGrant;
use App\Plugins\PluginHostClient;

[$script, $socket, $component, $helper] = array_pad($argv, 4, null);
if (! is_string($socket) || ! is_string($component) || ! is_string($helper)) {
    fwrite(STDERR, "usage: youtube-acquire-parity.php <socket> <component> <helper>\n");
    exit(64);
}

$url = 'https://www.youtube.com/watch?v=StashdVid01';
$item = [
    'id' => 'StashdVid01',
    'reference' => $url,
    'title' => 'Stashd Video 1',
    'description' => 'Desc 1',
    'published_at' => '2026-01-01T00:00:00Z',
    'duration_seconds' => 600,
    'kind' => 'regular',
];
$root = sys_get_temp_dir() . '/stashd-youtube-acquire-parity-' . bin2hex(random_bytes(6));
$pluginStage = $root . '/plugin';
mkdir($pluginStage, 0700, true);

try {
    $plugin = (new PluginHostClient($socket))->acquireInput(
        $component,
        $item,
        $pluginStage,
        new PluginHelperGrant('yt-dlp', $helper),
    );
    $pluginPrimary = null;
    foreach ($plugin->acquisition['artifacts'] ?? [] as $artifact) {
        if (($artifact['role'] ?? null) === 'primary') {
            $pluginPrimary = $pluginStage . '/' . $artifact['reference'];
            break;
        }
    }
    if (! is_string($pluginPrimary) || ! is_file($pluginPrimary)) {
        throw new RuntimeException('plugin primary artifact missing');
    }

    echo json_encode([
        'id' => $item['id'],
        'primary_sha256' => hash_file('sha256', $pluginPrimary),
        'plugin_roles' => array_column($plugin->acquisition['artifacts'], 'role'),
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (glob($root . '/plugin/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($pluginStage);
    rmdir($root);
}

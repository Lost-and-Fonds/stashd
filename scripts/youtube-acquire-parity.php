<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\YtdlpConfig;
use App\Downloads\DownloadRequest;
use App\Downloads\Ytdlp\StubYtdlpGateway;
use App\Downloads\Ytdlp\YtdlpDownloader;
use App\Downloads\Ytdlp\YtdlpOptionsBuilder;
use App\Plugins\PluginHelperGrant;
use App\Plugins\PluginHostClient;
use App\Providers\StashdUri;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Vault\AssetRole;
use App\Vault\MediaItemId;
use App\Vault\VaultSidecarBuilder;

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
$phpStage = $root . '/php';
mkdir($pluginStage, 0700, true);
mkdir($phpStage, 0700, true);

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

    $config = new YtdlpConfig('yt-dlp', 300, true, 'bestvideo', 'mp3', 128);
    $oracle = new YtdlpDownloader(
        $config,
        new StubYtdlpGateway(),
        new YtdlpOptionsBuilder($config),
        new VaultSidecarBuilder(),
    );
    $phpResult = $oracle->download(new DownloadRequest(
        mediaItemId: new MediaItemId('media_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        stashId: new StashId('stash_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        providerKey: 'youtube',
        providerItemId: 'StashdVid01',
        canonicalUri: StashdUri::parse($url),
        downloadPolicy: DownloadPolicy::Video,
        tempDirectory: $phpStage,
        title: 'Stashd Video 1',
        durationSeconds: 600,
    ));
    $phpPrimary = array_values(array_filter($phpResult->files, static fn ($file): bool => $file->role === AssetRole::VaultOriginal))[0] ?? null;
    if ($phpPrimary === null || ! hash_equals(hash_file('sha256', $phpPrimary->tempPath), hash_file('sha256', $pluginPrimary))) {
        throw new RuntimeException('primary artifact parity failed');
    }
    echo json_encode([
        'id' => $item['id'],
        'primary_sha256' => hash_file('sha256', $pluginPrimary),
        'php_roles' => array_map(static fn ($file): string => $file->role->value, $phpResult->files),
        'plugin_roles' => array_column($plugin->acquisition['artifacts'], 'role'),
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (glob($root . '/plugin/*') ?: [] as $path) {
        unlink($path);
    }
    foreach (glob($root . '/php/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($pluginStage);
    rmdir($phpStage);
    rmdir($root);
}

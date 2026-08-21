<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHelperGrant;
use App\Plugins\PluginHostClient;

[$script, $socket, $component, $helper] = array_pad($argv, 4, null);
if (! is_string($socket) || ! is_string($component) || ! is_string($helper)) {
    fwrite(STDERR, "usage: youtube-acquire-spike.php <socket> <component> <helper>\n");
    exit(64);
}

$item = [
    'id' => 'StashdVid01',
    'reference' => 'https://www.youtube.com/watch?v=StashdVid01',
    'title' => 'Stashd Video 1',
    'description' => 'Desc 1',
    'published_at' => '2026-01-01T00:00:00Z',
    'artwork_reference' => 'https://i.ytimg.com/vi/StashdVid01/hqdefault.jpg',
    'duration_seconds' => 600,
    'kind' => 'regular',
];
$staging = sys_get_temp_dir() . '/stashd-youtube-acquire-' . bin2hex(random_bytes(6));
mkdir($staging, 0700, true);

try {
    $result = (new PluginHostClient($socket))->acquireInput(
        $component,
        $item,
        $staging,
        new PluginHelperGrant('yt-dlp', $helper),
        includeCaptions: true,
    );
    $artifacts = $result->acquisition['artifacts'] ?? [];
    $roles = array_column($artifacts, 'role');
    foreach (['primary', 'captions', 'artwork', 'metadata'] as $role) {
        if (! in_array($role, $roles, true)) {
            throw new RuntimeException("missing {$role} artifact");
        }
    }
    foreach ($artifacts as $artifact) {
        $reference = $artifact['reference'] ?? null;
        if (! is_string($reference) || str_contains($reference, '/') || str_starts_with($reference, '.')) {
            throw new RuntimeException('artifact reference was not a safe staging reference');
        }
        if (! is_file($staging . '/' . $reference)) {
            throw new RuntimeException("staged artifact missing: {$reference}");
        }
    }
    if (! in_array('finalizing artifacts', array_column($result->progress, 'stage'), true)) {
        throw new RuntimeException('artifact finalization progress was not reported');
    }
    if (! in_array('complete', array_column($result->progress, 'stage'), true)) {
        throw new RuntimeException('acquisition completion progress was not reported');
    }
    echo json_encode(['roles' => $roles, 'progress' => $result->progress, 'logs' => $result->logs], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (glob($staging . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($staging);
}

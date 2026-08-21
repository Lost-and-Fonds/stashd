<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrant;

[$script, $socket, $component, $fixtureDirectory, $source] = array_pad($argv, 5, null);

if ($socket === null || $component === null || $fixtureDirectory === null || $source === null) {
    fwrite(STDERR, "usage: youtube-input-plugin.php <socket> <component> <fixture-directory> <source-uri>\n");
    exit(64);
}

try {
    $client = new PluginHostClient($socket);
    $httpGrant = new PluginHttpGrant([
        'https://www.youtube.com/@',
        'https://www.youtube.com/c/',
        'https://www.youtube.com/user/',
        'https://www.youtube.com/channel/',
        'https://www.youtube.com/feeds/videos.xml?channel_id=',
        'https://www.youtube.com/feeds/videos.xml?playlist_id=',
        'https://www.youtube.com/oembed?format=json&url=',
    ]);
    $resolved = $client->resolveInput($component, $source, $fixtureDirectory, [$httpGrant]);
    $inputId = $resolved->resolved['id'] ?? null;
    if (! is_string($inputId)) {
        throw new RuntimeException('plugin returned no input ID');
    }
    $discovered = $client->discoverInput($component, $inputId, $fixtureDirectory, httpGrants: [$httpGrant]);

    $items = $discovered->items ?? [];
    $expectedKind = str_contains($source, 'playlist')
        ? 'playlist'
        : ((str_contains($source, 'watch') || str_contains($source, 'shorts') || str_contains($source, 'youtu.be')) ? 'video' : 'channel');
    if (($resolved->resolved['kind'] ?? null) !== $expectedKind
        || $items === []
        || ($items[0]['id'] ?? null) === null
        || ($items[0]['title'] ?? null) === null
        || ($items[0]['reference'] ?? null) === null) {
        throw new RuntimeException('YouTube plugin source/discovery check failed: ' . json_encode([
            'plugin_resolved' => $resolved->resolved,
            'plugin_first_item' => $items[0] ?? null,
            'plugin_count' => count($items),
        ], JSON_THROW_ON_ERROR));
    }

    echo json_encode([
        'plugin' => ['resolved' => $resolved->resolved, 'items' => $items, 'progress' => [...$resolved->progress, ...$discovered->progress], 'logs' => [...$resolved->logs, ...$discovered->logs]],
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginCredentialGrant;
use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrant;

[$script, $socket, $component, $fixtureDirectory] = array_pad($argv, 4, null);
if (! is_string($socket) || ! is_string($component) || ! is_string($fixtureDirectory)) {
    fwrite(STDERR, "usage: youtube-data-api-parity.php <socket> <component> <fixture-directory>\n");
    exit(64);
}

try {
    $secret = 'fixture-secret-do-not-cross-wasm';
    $plugin = (new PluginHostClient($socket))->discoverInput(
        $component,
        'UCStashdDemoCh0012345678',
        $fixtureDirectory,
        'complete',
        httpGrants: [new PluginHttpGrant(
            [
                'https://www.googleapis.com/youtube/v3/channels?',
                'https://www.googleapis.com/youtube/v3/playlistItems?',
                'https://www.googleapis.com/youtube/v3/videos?',
            ],
            new PluginCredentialGrant('youtube-data-api', $secret, 'key'),
        )],
        options: ['include_shorts' => true, 'include_live' => true],
    );

    $pluginItems = $plugin->items ?? [];
    if (count($pluginItems) !== 18) {
        throw new RuntimeException('Data API fixture discovery count failed: ' . count($pluginItems));
    }
    foreach ($pluginItems as $index => $actual) {
        if (! is_array($actual)) {
            throw new RuntimeException("Data API item {$index} was invalid");
        }
        foreach (['id', 'reference', 'title', 'description', 'published_at', 'artwork_reference', 'duration_seconds', 'kind'] as $field) {
            if (! array_key_exists($field, $actual)) {
                throw new RuntimeException("Data API item {$index} omitted {$field}");
            }
        }
    }

    $output = json_encode(['count' => count($pluginItems), 'first' => $pluginItems[0] ?? null, 'progress' => $plugin->progress, 'logs' => $plugin->logs], JSON_THROW_ON_ERROR);
    if (str_contains($output, $secret)) {
        throw new RuntimeException('credential secret appeared in plugin-visible output');
    }
    echo $output . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

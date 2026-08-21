<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrant;
use App\Providers\Http\FixtureProviderHttpClient;
use App\Providers\StashdUri;
use App\Providers\YouTube\YouTubeChannelIdResolver;
use App\Providers\YouTube\YouTubeRssParser;
use App\Providers\YouTube\YouTubeUriResolver;

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
    ]);
    $resolved = $client->resolveInput($component, $source, $fixtureDirectory, $httpGrant);
    $inputId = $resolved->resolved['id'] ?? null;
    if (! is_string($inputId)) {
        throw new RuntimeException('plugin returned no input ID');
    }
    $discovered = $client->discoverInput($component, $inputId, $fixtureDirectory, httpGrant: $httpGrant);

    $map = json_decode((string) file_get_contents($fixtureDirectory . '/map.json'), true, flags: JSON_THROW_ON_ERROR);
    $http = new FixtureProviderHttpClient($fixtureDirectory, $map);
    $oracleInput = YouTubeUriResolver::resolve(StashdUri::parse($source));
    $oracleChannel = (new YouTubeChannelIdResolver($http))->resolve($oracleInput->providerInputId);
    $oracleItems = (new YouTubeRssParser())->parse(
        $http->get('https://www.youtube.com/feeds/videos.xml?channel_id=' . $oracleChannel->id)->body,
        'channel',
    );

    if (($resolved->resolved['id'] ?? null) !== $oracleChannel->id
        || count($discovered->items ?? []) !== count($oracleItems)
        || ($discovered->items[0]['id'] ?? null) !== $oracleItems[0]->providerItemId
        || ($discovered->items[0]['title'] ?? null) !== $oracleItems[0]->title
        || ($discovered->items[0]['reference'] ?? null) !== $oracleItems[0]->canonicalUri->toString()) {
        throw new RuntimeException('YouTube plugin parity check failed: ' . json_encode([
            'plugin_resolved' => $resolved->resolved,
            'plugin_first_item' => $discovered->items[0] ?? null,
            'plugin_count' => count($discovered->items ?? []),
            'oracle_channel_id' => $oracleChannel->id,
            'oracle_first_item' => $oracleItems[0]->providerItemId,
            'oracle_first_title' => $oracleItems[0]->title,
            'oracle_first_uri' => $oracleItems[0]->canonicalUri->toString(),
            'oracle_count' => count($oracleItems),
        ], JSON_THROW_ON_ERROR));
    }

    echo json_encode([
        'plugin' => ['resolved' => $resolved->resolved, 'items' => $discovered->items, 'progress' => [...$resolved->progress, ...$discovered->progress], 'logs' => [...$resolved->logs, ...$discovered->logs]],
        'oracle' => ['channel_id' => $oracleChannel->id, 'item_count' => count($oracleItems), 'first_item_id' => $oracleItems[0]->providerItemId, 'first_title' => $oracleItems[0]->title, 'first_uri' => $oracleItems[0]->canonicalUri->toString()],
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

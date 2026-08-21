<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginCredentialGrant;
use App\Plugins\PluginHostClient;
use App\Providers\Http\FixtureProviderHttpClient;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\YouTube\FixtureYouTubeDataApiKeyResolver;
use App\Providers\YouTube\YouTubeDataApiDiscoveryStrategy;

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
        new PluginCredentialGrant('youtube-data-api', $secret),
    );

    $map = json_decode((string) file_get_contents($fixtureDirectory . '/map.json'), true, flags: JSON_THROW_ON_ERROR);
    $oracle = new YouTubeDataApiDiscoveryStrategy(
        new FixtureYouTubeDataApiKeyResolver('test-api-key'),
        new FixtureProviderHttpClient($fixtureDirectory, $map),
    );
    $oracleItems = $oracle->discover(new ResolvedInput(
        providerKey: 'youtube',
        inputType: 'channel',
        sourceUri: StashdUri::parse('https://www.youtube.com/channel/UCStashdDemoCh0012345678'),
        providerInputId: 'UCStashdDemoCh0012345678',
    ));

    $pluginItems = $plugin->items ?? [];
    if (count($pluginItems) !== count($oracleItems)) {
        throw new RuntimeException('Data API candidate count parity failed');
    }
    foreach ($oracleItems as $index => $item) {
        $actual = $pluginItems[$index] ?? [];
        $expected = [
            'id' => $item->providerItemId,
            'reference' => $item->canonicalUri->toString(),
            'title' => $item->title,
            'description' => $item->description,
            'published_at' => $item->publishedAt?->toRfc3339(useZ: true),
            'artwork_reference' => $item->thumbnailUri?->toString(),
            'duration_seconds' => $item->durationSeconds,
            'kind' => $item->contentType,
        ];
        foreach ($expected as $field => $value) {
            if ($field === 'published_at' && $value !== null) {
                if (! is_string($actual[$field] ?? null) || ! is_string($value)) {
                    throw new RuntimeException("Data API parity timestamp types failed: actual=" . json_encode($actual[$field] ?? null) . " expected=" . json_encode($value));
                }
                try {
                    $actualTimestamp = isset($actual[$field]) ? new DateTimeImmutable($actual[$field]) : null;
                    $expectedTimestamp = new DateTimeImmutable($value);
                } catch (Throwable $exception) {
                    throw new RuntimeException("Data API parity timestamp parse failed: actual=" . json_encode($actual[$field] ?? null) . " expected=" . json_encode($value), previous: $exception);
                }
                if ($actualTimestamp?->getTimestamp() !== $expectedTimestamp->getTimestamp()) {
                    throw new RuntimeException("Data API parity failed for {$field} at item {$index}: actual=" . json_encode($actual[$field] ?? null) . " expected=" . json_encode($value));
                }
                continue;
            }
            if (($actual[$field] ?? null) !== $value) {
                throw new RuntimeException("Data API parity failed for {$field} at item {$index}");
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

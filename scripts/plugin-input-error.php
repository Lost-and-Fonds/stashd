<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginCredentialGrant;
use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrant;

[$script, $socket, $component, $fixtureDirectory, $operation, $value, $expected, $intent, $credentialName, $credentialValue] = array_pad($argv, 10, null);

if (! is_string($socket) || ! is_string($component) || ! is_string($fixtureDirectory) || ! is_string($operation) || ! is_string($value) || ! is_string($expected)) {
    fwrite(STDERR, "usage: plugin-input-error.php <socket> <component> <fixture-directory> <resolve|discover> <value> <expected-error> [intent] [credential-name] [credential-value]\n");
    exit(64);
}

try {
    $client = new PluginHostClient($socket);
    $prefixes = $operation === 'resolve'
        ? ['https://www.youtube.com/']
        : [
            'https://www.youtube.com/feeds/videos.xml?channel_id=',
            'https://www.googleapis.com/youtube/v3/channels?',
            'https://www.googleapis.com/youtube/v3/playlistItems?',
            'https://www.googleapis.com/youtube/v3/videos?',
        ];
    $httpGrant = new PluginHttpGrant(
        $prefixes,
        is_string($credentialName) && is_string($credentialValue)
            ? new PluginCredentialGrant($credentialName, $credentialValue, 'key')
            : null,
    );

    if ($operation === 'resolve') {
        $client->resolveInput($component, $value, $fixtureDirectory, [$httpGrant]);
    } else {
        $client->discoverInput(
            $component,
            $value,
            $fixtureDirectory,
            is_string($intent) ? $intent : 'refresh',
            httpGrants: [$httpGrant],
        );
    }
    fwrite(STDERR, "expected plugin error was not returned\n");
    exit(1);
} catch (Throwable $exception) {
    if (! str_contains($exception->getMessage(), "[{$expected}]:") || ($credentialValue !== null && str_contains($exception->getMessage(), (string) $credentialValue))) {
        fwrite(STDERR, "unexpected plugin error: {$exception->getMessage()}\n");
        exit(1);
    }
    echo "{$expected}\n";
}

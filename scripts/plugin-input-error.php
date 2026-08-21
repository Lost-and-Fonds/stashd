<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHostClient;

[$script, $socket, $component, $fixtureDirectory, $operation, $value, $expected, $intent, $credentialName, $credentialValue] = array_pad($argv, 10, null);

if (! is_string($socket) || ! is_string($component) || ! is_string($fixtureDirectory) || ! is_string($operation) || ! is_string($value) || ! is_string($expected)) {
    fwrite(STDERR, "usage: plugin-input-error.php <socket> <component> <fixture-directory> <resolve|discover> <value> <expected-error> [intent] [credential-name] [credential-value]\n");
    exit(64);
}

try {
    $client = new PluginHostClient($socket);
    if ($operation === 'resolve') {
        $client->resolveInput($component, $value, $fixtureDirectory);
    } else {
        $client->discoverInput(
            $component,
            $value,
            $fixtureDirectory,
            is_string($intent) ? $intent : 'refresh',
            is_string($credentialName) && is_string($credentialValue)
                ? new App\Plugins\PluginCredentialGrant($credentialName, $credentialValue)
                : null,
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

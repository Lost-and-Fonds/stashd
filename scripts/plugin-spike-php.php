<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugins\PluginHostClient;
use App\Plugins\PluginInvocation;

[$script, $socket, $component, $asset, $staging, $operation] = array_pad($argv, 6, null);

if ($socket === null || $component === null || $asset === null || $staging === null) {
    fwrite(STDERR, "usage: plugin-spike-php.php <socket> <component> <asset> <staging> [operation]\n");
    exit(64);
}

try {
    $result = (new PluginHostClient($socket))->invoke(new PluginInvocation(
        componentPath: $component,
        assetPath: $asset,
        stagingPath: $staging,
        operation: $operation ?: 'copy',
    ));

    echo json_encode([
        'progress' => $result->progress,
        'logs' => $result->logs,
        'source_bytes' => $result->sourceBytes,
        'output_id' => $result->outputId,
        'output_bytes' => $result->outputBytes,
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

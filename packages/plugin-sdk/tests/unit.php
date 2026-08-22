<?php

declare(strict_types=1);

foreach (['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'] as $interface) {
    require_once __DIR__ . '/../src/' . $interface;
}

foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
    if (! in_array(basename($file), ['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'], true)) {
        require_once $file;
    }
}

use Stashd\PluginSdk\InvalidPluginResultException;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\PluginError;
use Stashd\PluginSdk\PluginErrorCode;
use Stashd\PluginSdk\PluginFailure;
use Stashd\PluginSdk\PluginFailureException;
use Stashd\PluginSdk\PluginInvoker;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\WireMapper;

if (OptionValue::text('fixture')->toWire() !== ['tag' => 'text', 'value' => 'fixture']) {
    throw new RuntimeException('SDK option mapping failed');
}
$failure = new PluginFailure(PluginErrorCode::Unavailable, new PluginError('temporary fixture failure', true));
$wireFailure = WireMapper::pluginFailure($failure);
if (! is_array($wireFailure['value'] ?? null) || ($wireFailure['value']['retryable'] ?? null) !== true) {
    throw new RuntimeException('SDK retryability mapping failed');
}
try {
    throw new PluginFailureException($failure);
} catch (PluginFailureException $exception) {
    if (! $exception->failure->error->retryable) {
        throw new RuntimeException('SDK typed error failed');
    }
}
try {
    PluginInvoker::publish(static fn(PublishRequest $request): mixed => 'invalid', new PublishRequest('fixture'));
    throw new RuntimeException('invalid SDK result was accepted');
} catch (InvalidPluginResultException) {
}
$contextReflection = new ReflectionClass(PluginContext::class);
$contextSource = (string) file_get_contents((string) $contextReflection->getFileName());
if (str_contains($contextSource, 'bubblewrap') || str_contains($contextSource, 'FrameCodec')) {
    throw new RuntimeException('sandbox/RPC mechanics leaked into SDK context');
}
echo "PHP SDK unit/conformance: PASS\n";

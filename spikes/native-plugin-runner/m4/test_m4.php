<?php

declare(strict_types=1);

use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\DiscoveryIntent;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\Logger;
use Stashd\PluginSdk\MediaKind;
use Stashd\PluginSdk\NullLogger;
use Stashd\PluginSdk\NullProgressReporter;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginBootstrap;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\PluginFailureException;
use Stashd\PluginSdk\PluginInvoker;
use Stashd\PluginSdk\ProgressReporter;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\Source;
use Stashd\PluginSdk\UnavailableHttpClient;
use Stashd\PluginSdk\WireMapper;

require_once __DIR__ . '/sdk/Sdk.php';
require_once __DIR__ . '/example/ExamplePlugin.php';

final class RecordingLogger implements Logger
{
    /** @var list<string> */
    public array $messages = [];
    public function info(string $message): void { $this->messages[] = 'info:' . $message; }
    public function error(string $message): void { $this->messages[] = 'error:' . $message; }
}

final class RecordingProgress implements ProgressReporter
{
    /** @var list<string> */
    public array $stages = [];
    public function report(string $stage): void { $this->stages[] = $stage; }
}

function canonical(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map('canonical', $value)) . ']';
        }
        ksort($value);
        return '{' . implode(',', array_map(static fn (string $key, mixed $item): string => json_encode($key) . ':' . canonical($item), array_keys($value), $value)) . '}';
    }
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

$logger = new RecordingLogger();
$progress = new RecordingProgress();
$entrypoint = new Stashd\ExamplePlugin\ExampleEntrypoint(new PluginContext($logger, $progress));
$registry = PluginBootstrap::load($entrypoint);
$broadcast = $registry->broadcastPlugin('example-broadcast');
$input = $registry->inputPlugin('example-input');

$request = new PublishRequest(
    'broadcast:1',
    [new Setting('format', OptionValue::text('mp3'))],
    [new Source('source:1')],
    [new Item('item:1', 'Episode', [], 'source:1')],
);
$preparation = $broadcast->prepare($request);
$publication = $broadcast->publish($request);
$finalized = $broadcast->finalize(new Stashd\PluginSdk\FinalizationRequest($request, $publication));
assert(count($preparation->artifacts) === 0);
assert($finalized === $publication);
assert($publication->files[0]->relativePath === 'items/item%3A1.bin');
assert($logger->messages === ['info:publishing example items']);
assert($progress->stages === ['prepare', 'publish', 'finalize']);

$resolved = $input->resolve('source');
$items = $input->discover($resolved->id, DiscoveryIntent::Complete);
$acquisition = $input->acquire($items[0], new AcquisitionOptions(MediaKind::Video));
assert($resolved->id === 'example-input');
assert($items[0]->id === 'example-item');
assert($acquisition->artifacts[0]->reference === 'example:example-item');

$wire = WireMapper::publishRequest($request);
$golden = json_decode(file_get_contents(__DIR__ . '/../m3/goldens/publish-request.golden'), true, 512, JSON_THROW_ON_ERROR);
assert($wire['reference'] === 'broadcast:1');
assert($wire['items'][0]['id'] === 'item:1');
assert($wire['settings'][0]['value'] === ['tag' => 'text', 'value' => 'mp3']);
assert($golden['type'] === 'publish-request');
assert(canonical($wire) === canonical($golden['value']));

try {
    $broadcast->operation(new OperationRequest('fail'));
    assert(false, 'expected typed plugin failure');
} catch (PluginFailureException $exception) {
    assert($exception->failure->code->value === 'unavailable');
    assert($exception->failure->error->retryable === true);
    assert($exception->getTrace()[0]['function'] === 'operation');
    assert($exception->getTrace()[0]['class'] === Stashd\ExamplePlugin\ExampleBroadcast::class);
}

try {
    PluginInvoker::publish(static fn (PublishRequest $ignored): array => ['malformed'], $request);
    assert(false, 'expected invalid plugin result');
} catch (Stashd\PluginSdk\InvalidPluginResultException $exception) {
    assert(str_contains($exception->getMessage(), 'invalid result'));
}

try {
    (new UnavailableHttpClient())->request('GET', 'https://fixture.invalid');
    assert(false, 'expected unavailable fixture capability');
} catch (Stashd\PluginSdk\CapabilityUnavailableException) {
    assert(true);
}

$authorFiles = glob(__DIR__ . '/example/*.php') ?: [];
foreach ($authorFiles as $authorFile) {
    $source = strtolower(file_get_contents($authorFile));
    foreach (['rpc', 'bubblewrap', 'length-prefix', 'file descriptor', 'mount namespace'] as $forbidden) {
        assert(!str_contains($source, $forbidden), "$forbidden leaked into plugin author code");
    }
}

assert($registry->broadcastPlugin('example-broadcast') instanceof BroadcastPlugin);
assert($logger instanceof Logger);
assert($progress instanceof ProgressReporter);
assert(new NullLogger() instanceof Logger);
assert(new NullProgressReporter() instanceof ProgressReporter);
echo "M4 PHP SDK/example conformance: PASS\n";

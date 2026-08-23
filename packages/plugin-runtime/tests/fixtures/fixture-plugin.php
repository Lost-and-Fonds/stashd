<?php

declare(strict_types=1);

foreach (['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'] as $interface) {
    require_once __DIR__ . '/sdk/' . $interface;
}

foreach (glob(__DIR__ . '/sdk/*.php') ?: [] as $sdkFile) {
    if (! in_array(basename($sdkFile), ['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'], true)) {
        require_once $sdkFile;
    }
}
require_once __DIR__ . '/rpc/FrameCodec.php';

use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\AcquisitionResult;
use Stashd\PluginSdk\Artifact;
use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\Choice;
use Stashd\PluginSdk\DiscoveredItem;
use Stashd\PluginSdk\DiscoveryIntent;
use Stashd\PluginSdk\FinalizationRequest;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\InputPlugin;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\Logger;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\OperationResult;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\PluginError;
use Stashd\PluginSdk\PluginErrorCode;
use Stashd\PluginSdk\PluginFailure;
use Stashd\PluginSdk\PluginFailureException;
use Stashd\PluginSdk\Preparation;
use Stashd\PluginSdk\ProgressReporter;
use Stashd\PluginSdk\Publication;
use Stashd\PluginSdk\PublishedFile;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\ReadableResource;
use Stashd\PluginSdk\ResolvedInput;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\StagingArea;
use Stashd\PluginSdk\WireMapper;

final class M7Rpc
{
    private int $nextId = 1;

    public function __construct()
    {
        $this->hello();
    }

    public function call(string $method, array $params): array
    {
        $id = 'plugin-' . $this->nextId++;
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => $method, 'params' => $params]);
        while (true) {
            $message = FrameCodec::read(STDIN, 5.0);
            if (($message['kind'] ?? null) === 'notification') {
                continue;
            }
            if (($message['id'] ?? null) !== $id) {
                throw new RuntimeException('RPC response ID mismatch');
            }
            if (isset($message['error'])) {
                throw new RuntimeException((string) ($message['error']['message'] ?? 'host capability failed'));
            }

            return $message['result'] ?? [];
        }
    }

    public function hello(): void
    {
        $id = 'hello';
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => 'hello', 'params' => ['min' => 1, 'max' => 1]]);
        $response = FrameCodec::read(STDIN, 5.0);
        if (($response['id'] ?? null) !== $id || ($response['result']['protocol'] ?? null) !== 1) {
            throw new RuntimeException('RPC handshake failed');
        }
    }
}

final class M7Resource implements ReadableResource
{
    private bool $eof = false;

    public function __construct(private M7Rpc $rpc, private string $reference) {}

    public function read(int $maximumBytes = 65536): string
    {
        if ($this->eof) {
            return '';
        }
        $result = $this->rpc->call('resource.read', ['reference' => $this->reference, 'maximum_bytes' => $maximumBytes]);
        $this->eof = (bool) ($result['eof'] ?? true);

        return base64_decode((string) ($result['data'] ?? ''), true) ?: '';
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        $this->eof = true;
    }
}

final class M7Http implements HttpClient
{
    public function __construct(private M7Rpc $rpc) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $result = $this->rpc->call('http.request', compact('method', 'url', 'headers', 'body', 'credential'));
        $resource = isset($result['resource']) ? new M7Resource($this->rpc, (string) $result['resource']) : null;

        return new HttpResponse((int) $result['status'], $result['headers'] ?? [], $resource === null ? (string) ($result['body'] ?? '') : null, $resource);
    }
}

final class M7Log implements Logger
{
    public function __construct(private M7Rpc $rpc) {}

    public function info(string $message): void
    {
        $this->rpc->call('event.log', ['message' => $message]);
    }

    public function error(string $message): void
    {
        $this->rpc->call('event.log', ['message' => $message, 'level' => 'error']);
    }
}

final class M7Progress implements ProgressReporter
{
    public function __construct(private M7Rpc $rpc) {}

    public function report(string $stage): void
    {
        $this->rpc->call('event.progress', ['stage' => $stage]);
    }
}

final class M7Stage implements StagingArea
{
    public function __construct(private M7Rpc $rpc) {}

    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
    {
        $result = $this->rpc->call('staging.write', ['relative_path' => $relativePath, 'content' => base64_encode($content), 'media_type' => $mediaType]);

        return new StagedArtifact((string) $result['reference'], (string) $result['media_type'], (int) $result['size_bytes']);
    }

    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
    {
        $result = $this->rpc->call('staging.stage', ['relative_path' => $relativePath, 'media_type' => $mediaType]);

        return new StagedArtifact((string) $result['reference'], (string) $result['media_type'], (int) $result['size_bytes']);
    }
}

final class M7ExampleBroadcast implements BroadcastPlugin
{
    public function __construct(private PluginContext $context, private M7Rpc $rpc) {}

    public function prepare(PublishRequest $request): Preparation
    {
        $this->context->progress->report('prepare');

        return new Preparation();
    }

    public function publish(PublishRequest $request): Publication
    {
        $this->context->logger->info('example broadcast publish');
        $this->context->progress->report('publish');
        $small = $this->context->http->request('GET', 'https://allowed.test/small', [], null, 'fixture-token');
        if ($small->body() !== 'small-response') {
            throw new RuntimeException('small broker response mismatch');
        }
        $large = $this->context->http->request('GET', 'https://allowed.test/large');
        $bytes = 0;
        if ($large->resource === null) {
            throw new RuntimeException('large broker response was not a resource');
        }
        while (! $large->resource->isEof()) {
            $bytes += strlen($large->resource->read(8192));
        }
        if ($bytes !== 300_000) {
            throw new RuntimeException('large broker resource mismatch');
        }
        $asset = $this->rpc->call('asset.read', ['reference' => 'asset-1']);
        if (base64_decode((string) $asset['data'], true) !== 'asset-content') {
            throw new RuntimeException('asset handle mismatch');
        }
        $helper = $this->rpc->call('helper.run', ['name' => 'fixture-helper', 'arguments' => []]);
        if ((int) $helper['exit_code'] !== 0) {
            throw new RuntimeException('helper failed');
        }
        $this->context->staging?->write('published/item-1.bin', 'authoritative fixture output', 'application/octet-stream');
        if (($request->settings[0]->value->value ?? null) === 'fail') {
            throw new PluginFailureException(new PluginFailure(PluginErrorCode::Failed, new PluginError('fixture publish failure', true)));
        }

        return new Publication(new Artifact('example:publication', 'application/octet-stream', 28), [new PublishedFile('item-1', 'source-1', 'published/item-1.bin')]);
    }

    public function finalize(FinalizationRequest $request, PluginContext $context): Publication
    {
        $this->context->progress->report('finalize');

        return $request->publication;
    }

    public function operation(OperationRequest $request, PluginContext $context): OperationResult
    {
        return new OperationResult([new Choice('fixture', 'Fixture choice')], [new Setting('echo', OptionValue::text($request->name))]);
    }
}

final class M7ExampleInput implements InputPlugin
{
    public function __construct(private PluginContext $context) {}

    public function resolve(string $source): ResolvedInput
    {
        return new ResolvedInput('input-1', 'fixture:' . $source, 'fixture', 'Fixture input');
    }

    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array
    {
        return [new DiscoveredItem('item-1', 'fixture:item-1', 'Fixture item', kind: 'binary')];
    }

    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult
    {
        $artifact = $this->context->staging?->write('inputs/item-1.bin', 'input fixture', 'application/octet-stream');

        return new AcquisitionResult($artifact === null ? [] : [$artifact]);
    }
}

$rpc = new M7Rpc();
$context = new PluginContext(new M7Log($rpc), new M7Progress($rpc), new M7Http($rpc), new M7Stage($rpc));
while (($message = FrameCodec::read(STDIN, 10.0)) !== null) {
    $id = $message['id'] ?? null;

    try {
        $method = $message['method'] ?? '';
        $result = match ($method) {
            'broadcast.publish' => WireMapper::publication((new M7ExampleBroadcast($context, $rpc))->publish(new PublishRequest('fixture', [new Setting('mode', OptionValue::text((string) ($message['params']['mode'] ?? 'ok')))], [], [new Item('item-1', 'Fixture item', [], 'source-1')]))),
            'broadcast.prepare' => ['artifacts' => []],
            'broadcast.finalize' => WireMapper::publication((new M7ExampleBroadcast($context, $rpc))->finalize(new FinalizationRequest(new PublishRequest('fixture'), new Publication(new Artifact('example:publication'))), $context)),
            'broadcast.operation' => ['choices' => [['value' => 'fixture', 'label' => 'Fixture choice']], 'values' => [['key' => 'echo', 'value' => ['tag' => 'text', 'value' => 'fixture']]]],
            'input.resolve' => ['id' => 'input-1', 'canonical-reference' => 'fixture:source', 'kind' => 'fixture', 'title' => 'Fixture input', 'artwork-reference' => null, 'estimated-item-count' => 1],
            'input.discover' => [['id' => 'item-1', 'reference' => 'fixture:item-1', 'title' => 'Fixture item', 'description' => null, 'published-at' => null, 'artwork-reference' => null, 'duration-seconds' => null, 'kind' => 'binary']],
            'input.acquire' => ['artifacts' => [WireMapper::stagedArtifact($context->staging?->write('inputs/item-1.bin', 'input fixture', 'application/octet-stream'))]],
            default => throw new RuntimeException('unknown invocation method'),
        };
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'result' => $result]);
    } catch (PluginFailureException $exception) {
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'error' => WireMapper::pluginFailure($exception->failure)]);
    } catch (Throwable $exception) {
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'error' => ['code' => 'failed', 'message' => $exception->getMessage()]]);
    }
}

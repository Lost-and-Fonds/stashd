<?php

declare(strict_types=1);

use Stashd\NativeRuntime\Capabilities\CapabilityDenied;
use Stashd\NativeRuntime\Capabilities\CredentialGrant;
use Stashd\NativeRuntime\Capabilities\FixtureTransport;
use Stashd\NativeRuntime\Capabilities\HelperGrant;
use Stashd\NativeRuntime\Capabilities\Invocation;
use Stashd\NativeRuntime\Capabilities\TransportResponse;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Rpc\FrameCodec;
use Stashd\NativeRuntime\Runner\NativePluginRunner;
use Stashd\NativeRuntime\Sandbox\SandboxPolicy;
use Stashd\PluginSdk\ReadableResource;

foreach (['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'] as $interface) {
    require_once __DIR__ . '/../../plugin-sdk/src/' . $interface;
}

foreach (glob(__DIR__ . '/../../plugin-sdk/src/*.php') ?: [] as $sdkFile) {
    if (! in_array(basename($sdkFile), ['BroadcastPlugin.php', 'InputPlugin.php', 'Logger.php', 'ProgressReporter.php', 'ReadableResource.php', 'HttpClient.php', 'StagingArea.php'], true)) {
        require_once $sdkFile;
    }
}
require_once __DIR__ . '/../src/Capabilities/Invocation.php';
require_once __DIR__ . '/../src/Package/PackageManager.php';
require_once __DIR__ . '/../src/Rpc/FrameCodec.php';
require_once __DIR__ . '/../src/Sandbox/SandboxPolicy.php';
require_once __DIR__ . '/../src/Runner/NativePluginProcess.php';
require_once __DIR__ . '/../src/Runner/NativePluginRunner.php';

function m7Temp(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    if (! mkdir($path, 0700, true)) {
        throw new RuntimeException('temporary directory could not be created');
    }

    return $path;
}

function m7Remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            m7Remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

function m7Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function m7Archive(string $source, string $archive): void
{
    $files = ['plugin.json', 'plugin.php', 'rpc/FrameCodec.php', 'helpers/fixture-helper.php'];
    foreach (glob($source . '/sdk/*.php') ?: [] as $sdkFile) {
        $files[] = 'sdk/' . basename($sdkFile);
    }
    $process = proc_open(array_merge(['tar', '-czf', $archive, '-C', $source], $files), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('fixture archive failed');
    }
}

final class M7Metrics
{
    public int $invocations = 0;

    public int $capabilityCalls = 0;

    public int $resourceBytes = 0;

    public int $logs = 0;

    public int $progress = 0;

    public int $failures = 0;

    public array $events = [];
}

final class M7HostProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $nextHostId = 1;

    /** @var array<string, ReadableResource> */
    private array $resources = [];

    public function __construct(
        private string $packageRoot,
        private string $stagingRoot,
        private Invocation $invocation,
        private M7Metrics $metrics,
    ) {
        $command = (new SandboxPolicy())->command($packageRoot, $this->invocationRoot(), 'plugin.php');
        $this->pipes = [];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->process)) {
            throw new RuntimeException('native fixture process could not start');
        }
        stream_set_blocking($this->pipes[2], false);
        $this->handshake();
    }

    public function invoke(string $method, array $params = []): array
    {
        $id = 'host-' . $this->nextHostId++;
        FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => $method, 'params' => $params]);
        while (true) {
            $message = FrameCodec::read($this->pipes[1], 5.0);
            if ($message === null) {
                throw new RuntimeException('plugin exited before responding');
            }
            if (($message['kind'] ?? null) !== 'request') {
                if (($message['id'] ?? null) === $id && isset($message['error'])) {
                    $this->metrics->failures++;

                    return ['error' => $message['error']];
                }
                if (($message['id'] ?? null) === $id) {
                    return $message['result'] ?? [];
                }
                throw new RuntimeException('unexpected plugin response');
            }
            $this->metrics->capabilityCalls++;
            $this->respondToCapability($message);
        }
    }

    public function stderr(): string
    {
        $value = stream_get_contents($this->pipes[2]);

        return is_string($value) ? $value : '';
    }

    public function close(): int
    {
        if (is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        $exit = proc_close($this->process);
        $this->invocation->close();

        return $exit;
    }

    private function handshake(): void
    {
        $message = FrameCodec::read($this->pipes[1], 5.0);
        m7Assert(($message['method'] ?? null) === 'hello', 'plugin handshake was not hello');
        FrameCodec::write($this->pipes[0], [
            'protocol' => 1, 'id' => $message['id'], 'kind' => 'response',
            'result' => ['protocol' => 1, 'min' => 1, 'max' => 1],
        ]);
    }

    private function respondToCapability(array $message): void
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        try {
            $result = match ($method) {
                'http.request' => $this->http($message['params'] ?? []),
                'resource.read' => $this->resourceRead($message['params'] ?? []),
                'asset.read' => $this->assetRead($message['params'] ?? []),
                'staging.write' => $this->stagingWrite($message['params'] ?? []),
                'helper.run' => $this->helper($message['params'] ?? []),
                'event.log' => $this->event('log', (string) (($message['params'] ?? [])['message'] ?? '')),
                'event.progress' => $this->event('progress', (string) (($message['params'] ?? [])['stage'] ?? '')),
                default => throw new CapabilityDenied('unknown capability'),
            };
            FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'result' => $result]);
        } catch (Throwable $exception) {
            $this->metrics->failures++;
            FrameCodec::write($this->pipes[0], [
                'protocol' => 1, 'id' => $id, 'kind' => 'response',
                'error' => ['code' => 'capability-denied', 'message' => $exception->getMessage()],
            ]);
        }
    }

    private function http(array $params): array
    {
        $response = $this->invocation->http(
            (string) ($params['method'] ?? 'GET'),
            (string) ($params['url'] ?? ''),
            is_array($params['headers'] ?? null) ? $params['headers'] : [],
            isset($params['body']) ? (string) $params['body'] : null,
            isset($params['credential']) ? (string) $params['credential'] : null,
        );
        if ($response->resource === null) {
            return ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body()];
        }
        $reference = 'resource-' . count($this->resources) . '-' . bin2hex(random_bytes(4));
        $this->resources[$reference] = $response->resource;

        return ['status' => $response->status, 'headers' => $response->headers, 'resource' => $reference];
    }

    private function resourceRead(array $params): array
    {
        $reference = (string) ($params['reference'] ?? '');
        $resource = $this->resources[$reference] ?? throw new CapabilityDenied('resource is not granted');
        $data = $resource->read(max(1, (int) ($params['maximum_bytes'] ?? 65536)));
        $this->metrics->resourceBytes += strlen($data);

        return ['data' => base64_encode($data), 'eof' => $resource->isEof()];
    }

    private function assetRead(array $params): array
    {
        $resource = $this->invocation->readAsset((string) ($params['reference'] ?? ''));
        $reference = 'asset-' . bin2hex(random_bytes(4));
        $this->resources[$reference] = $resource;

        return $this->resourceRead(['reference' => $reference, 'maximum_bytes' => 65536]);
    }

    private function stagingWrite(array $params): array
    {
        $content = base64_decode((string) ($params['content'] ?? ''), true);
        if ($content === false) {
            throw new CapabilityDenied('staging content is invalid');
        }
        $artifact = $this->invocation->staging()->write((string) ($params['relative_path'] ?? ''), $content, $params['media_type'] ?? null);

        return ['reference' => $artifact->reference, 'media_type' => $artifact->mediaType, 'size_bytes' => $artifact->sizeBytes];
    }

    private function helper(array $params): array
    {
        $result = $this->invocation->runHelper((string) ($params['name'] ?? ''), $params['arguments'] ?? []);

        return ['exit_code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
    }

    private function event(string $kind, string $value): array
    {
        $this->metrics->{$kind === 'log' ? 'logs' : 'progress'}++;
        $this->metrics->events[] = ['kind' => $kind, 'value' => $value];

        return ['accepted' => true];
    }

    private function invocationRoot(): string
    {
        return $this->stagingRoot;
    }
}

function promote(string $stagingRoot, string $relativePath, string $promotionRoot): string
{
    if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
        throw new RuntimeException('publication path is unsafe');
    }
    $parts = explode('/', $relativePath);
    if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
        throw new RuntimeException('publication path is unsafe');
    }
    $source = realpath($stagingRoot . '/' . $relativePath);
    $staging = realpath($stagingRoot);
    if ($source === false || $staging === false || ! is_file($source) || ! str_starts_with($source, $staging . '/')) {
        throw new RuntimeException('publication source is outside staging');
    }
    $destination = $promotionRoot . '/' . $relativePath;
    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
        throw new RuntimeException('promotion directory could not be created');
    }
    if (! copy($source, $destination)) {
        throw new RuntimeException('promotion failed');
    }

    return $destination;
}

$root = m7Temp('stashd-m7');
try {
    $source = $root . '/source';
    mkdir($source . '/sdk', 0700, true);
    mkdir($source . '/rpc', 0700, true);
    mkdir($source . '/helpers', 0700, true);
    copy(__DIR__ . '/fixtures/fixture-plugin.php', $source . '/plugin.php');
    copy(__DIR__ . '/fixtures/fixture-helper.php', $source . '/helpers/fixture-helper.php');
    foreach (glob(__DIR__ . '/../../plugin-sdk/src/*.php') ?: [] as $sdkFile) {
        copy($sdkFile, $source . '/sdk/' . basename($sdkFile));
    }
    copy(__DIR__ . '/fixtures/FrameCodec.php', $source . '/rpc/FrameCodec.php');
    file_put_contents($source . '/plugin.json', json_encode([
        'id' => 'm7-example', 'name' => 'M7 Example', 'version' => '1.0.0', 'runtime' => 'php',
        'api_version' => '0.1', 'entrypoint' => 'plugin.php',
        'requires' => ['php' => '>=8.5', 'extensions' => []], 'architectures' => ['amd64', 'arm64'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $archive = $root . '/example.tar.gz';
    m7Archive($source, $archive);
    $manager = new PackageManager($root . '/plugins', '0.1', 'amd64');
    $manifest = $manager->install($archive, hash_file('sha256', $archive));
    $manager->activate($manifest->id, $manifest->version);
    m7Assert($manager->activeVersion('m7-example') === '1.0.0', 'package was not activated');
    $package = $manager->activePath('m7-example');
    m7Assert($package !== null, 'active package path is missing');
    $runnerSmokeStage = m7Temp('stashd-production-runner');
    $productionRunner = new NativePluginRunner($manager);
    $productionProcess = $productionRunner->start('m7-example', $runnerSmokeStage);
    $operation = $productionProcess->invoke('broadcast.operation', ['name' => 'runner-smoke'], static fn(array $message): array => []);
    m7Assert(($operation['choices'][0]['value'] ?? null) === 'fixture', 'production runner invocation failed');
    $productionProcess->close();
    m7Remove($runnerSmokeStage);

    $assetRoot = $root . '/assets';
    mkdir($assetRoot, 0700, true);
    file_put_contents($assetRoot . '/asset-1', 'asset-content');
    file_put_contents($assetRoot . '/neighbor', 'must-not-be-visible');
    $promotion = $root . '/promotion';
    mkdir($promotion, 0700, true);
    $metrics = new M7Metrics();
    $transport = new FixtureTransport(static function (string $method, string $url, array $headers): TransportResponse {
        m7Assert($method === 'GET', 'fixture request method mismatch');
        if ($url === 'https://allowed.test/small') {
            m7Assert(($headers['X-Fixture-Token'] ?? null) === 'fixture-secret', 'credential was not injected');

            return new TransportResponse(200, ['content-type' => 'text/plain'], ['small-response']);
        }
        if ($url === 'https://allowed.test/large') {
            return new TransportResponse(200, ['content-type' => 'application/octet-stream'], [str_repeat('a', 100000), str_repeat('b', 100000), str_repeat('c', 100000)]);
        }
        throw new CapabilityDenied('fixture destination denied');
    });
    $staging = m7Temp('stashd-m7-stage');
    $invocation = new Invocation($package, $staging, ['https://allowed.test'], [new CredentialGrant('fixture-token', 'https://allowed.test', 'X-Fixture-Token', 'fixture-secret')], $assetRoot, [new HelperGrant('fixture-helper', 'helpers/fixture-helper.php')], $transport, 64);
    $invocation->grantAsset('asset-1', $assetRoot . '/asset-1');
    $runner = new M7HostProcess($package, $staging, $invocation, $metrics);

    $resolved = $runner->invoke('input.resolve', ['source' => 'fixture:source']);
    m7Assert(($resolved['id'] ?? null) === 'input-1', 'Input resolve failed');
    $discovered = $runner->invoke('input.discover', ['input_id' => 'input-1']);
    m7Assert(($discovered[0]['id'] ?? null) === 'item-1', 'Input discover failed');
    $acquired = $runner->invoke('input.acquire', ['item_id' => 'item-1']);
    m7Assert(($acquired['artifacts'][0]['size-bytes'] ?? null) === 13, 'Input acquire failed: ' . json_encode($acquired, JSON_THROW_ON_ERROR));
    $prepared = $runner->invoke('broadcast.prepare');
    m7Assert(($prepared['artifacts'] ?? null) === [], 'Broadcast prepare failed');
    $choices = $runner->invoke('broadcast.operation', ['name' => 'discover-options']);
    m7Assert(($choices['choices'][0]['value'] ?? null) === 'fixture', 'dynamic choices failed');
    $publication = $runner->invoke('broadcast.publish', ['mode' => 'ok']);
    m7Assert(($publication['files'][0]['relative-path'] ?? null) === 'published/item-1.bin', 'Broadcast publication failed: ' . json_encode($publication, JSON_THROW_ON_ERROR));
    $helperReport = json_decode((string) file_get_contents($staging . '/helper-report.json'), true, 512, JSON_THROW_ON_ERROR);
    m7Assert($helperReport === ['vault' => 'denied', 'network' => 'denied', 'secret' => 'absent'], 'helper sandbox invariant failed');
    m7Assert(! file_exists($package . '/HELPER_MUTATION'), 'plugin package was writable');
    $published = promote($staging, 'published/item-1.bin', $promotion);
    $finalized = $runner->invoke('broadcast.finalize');
    m7Assert(isset($finalized['artifact']), 'Broadcast finalize failed');
    m7Assert(is_file($published) && file_get_contents($published) === 'authoritative fixture output', 'promotion output mismatch');
    m7Assert($metrics->logs > 0 && $metrics->progress > 0, 'structured events were not recorded');
    m7Assert(! str_contains(json_encode($metrics->events, JSON_THROW_ON_ERROR), 'fixture-secret'), 'credential leaked into events');
    $exit = $runner->close();
    m7Assert($exit === 0, 'successful plugin did not exit cleanly');
    m7Assert(! is_dir($staging), 'invocation staging was not cleaned');

    $retryStage = m7Temp('stashd-m7-retry');
    $retryInvocation = new Invocation($package, $retryStage, ['https://allowed.test'], [new CredentialGrant('fixture-token', 'https://allowed.test', 'X-Fixture-Token', 'fixture-secret')], $assetRoot, [new HelperGrant('fixture-helper', 'helpers/fixture-helper.php')], $transport, 64);
    $retryInvocation->grantAsset('asset-1', $assetRoot . '/asset-1');
    $retryMetrics = new M7Metrics();
    $retryRunner = new M7HostProcess($package, $retryStage, $retryInvocation, $retryMetrics);
    $failed = $retryRunner->invoke('broadcast.publish', ['mode' => 'fail']);
    m7Assert(isset($failed['error']), 'retry failure was not returned');
    m7Assert(! is_file($promotion . '/failed/item-1.bin'), 'failed publication was promoted');
    $retryRunner->close();
    m7Assert(! is_dir($retryStage), 'failed invocation staging was not cleaned');

    $retryStage = m7Temp('stashd-m7-rebuild');
    $retryInvocation = new Invocation($package, $retryStage, ['https://allowed.test'], [new CredentialGrant('fixture-token', 'https://allowed.test', 'X-Fixture-Token', 'fixture-secret')], $assetRoot, [new HelperGrant('fixture-helper', 'helpers/fixture-helper.php')], $transport, 64);
    $retryInvocation->grantAsset('asset-1', $assetRoot . '/asset-1');
    $retryRunner = new M7HostProcess($package, $retryStage, $retryInvocation, new M7Metrics());
    $rebuilt = $retryRunner->invoke('broadcast.publish', ['mode' => 'ok']);
    promote($retryStage, $rebuilt['files'][0]['relative-path'], $promotion);
    $retryRunner->close();
    m7Assert(count(glob($promotion . '/published/item-1.bin') ?: []) === 1, 'rebuild duplicated publication');

    $empty = new PackageManager($root . '/empty/plugins', '0.1', 'amd64');
    m7Assert($empty->activeVersion('missing-plugin') === null, 'absent plugin broke startup');
    m7Remove($root);
    echo "M7.5 production native runtime conformance: PASS\n";
} catch (Throwable $exception) {
    m7Remove($root);
    throw $exception;
}

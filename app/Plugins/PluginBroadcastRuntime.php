<?php

declare(strict_types=1);

namespace App\Plugins;

use GuzzleHttp\Psr7\Uri;
use RuntimeException;
use Stashd\PluginRuntime\Capabilities\CredentialGrant;
use Stashd\PluginRuntime\Capabilities\HelperGrant;
use Stashd\PluginRuntime\Capabilities\Invocation;
use Stashd\PluginRuntime\Capabilities\ReadableResource;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;
use Tempest\Support\Filesystem;

final readonly class PluginBroadcastRuntime implements BroadcastPluginRuntime
{
    public function __construct(
        private PluginRunner $runner,
        private PackageManager $packages,
        private string $pluginId,
    ) {}

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function prepare(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory, ?callable $onProgress = null): PluginBroadcastResult
    {
        return $this->invoke('broadcast.prepare', $stagingDirectory, $broadcast, $helper, $httpGrants, $fixtureDirectory, $onProgress);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function publish(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory, ?callable $onProgress = null): PluginBroadcastResult
    {
        return $this->invoke('broadcast.publish', $stagingDirectory, $broadcast, $helper, $httpGrants, $fixtureDirectory, $onProgress);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function finalize(string $stagingDirectory, array $broadcast, array $publication, ?array $httpGrants, ?string $fixtureDirectory, ?callable $onProgress = null): PluginBroadcastResult
    {
        return $this->invoke('broadcast.finalize', $stagingDirectory, ['request' => $broadcast, 'publication' => $publication], null, $httpGrants, $fixtureDirectory, $onProgress);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function operation(string $stagingDirectory, array $broadcast, string $operation, ?array $httpGrants, ?string $fixtureDirectory): array
    {
        /** @var array<string, mixed> $params */
        $params = [...$broadcast, 'name' => $operation];

        $result = $this->invokeRaw('broadcast.operation', $params, $stagingDirectory, null, $httpGrants, $fixtureDirectory);

        if (is_array($result['choices'] ?? null)) {
            $result['choices'] = array_map(static function (mixed $choice): mixed {
                if (! is_array($choice) || ! array_key_exists('label', $choice) || ! array_key_exists('value', $choice)) {
                    return $choice;
                }

                return ['label' => $choice['label'], 'value' => $choice['value']];
            }, $result['choices']);
        }

        return $result;
    }

    /** @param array<string, mixed> $broadcast
     * @param  list<PluginHttpGrant>|null  $httpGrants
     */
    private function invoke(string $method, string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory, ?callable $onProgress = null): PluginBroadcastResult
    {
        return new PluginBroadcastResult([], [], $this->normalizePublication($this->invokeRaw($method, $broadcast, $stagingDirectory, $helper, $httpGrants, $fixtureDirectory, $onProgress)));
    }

    /** @param array<string, mixed> $params
     * @param  list<PluginHttpGrant>|null  $httpGrants
     * @return array<string, mixed>
     */
    private function invokeRaw(string $method, array $params, string $stagingDirectory, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory, ?callable $onProgress = null): array
    {
        $package = $this->packages->activePath($this->pluginId);

        if ($package === null) {
            throw new RuntimeException('Plugin is not active: ' . $this->pluginId);
        }

        $credentials = [];
        $prefixes = [];

        foreach ($httpGrants ?? [] as $grant) {
            foreach ($grant->allowedPrefixes as $prefix) {
                $uri = new Uri($prefix);

                if ($uri->getScheme() === '' || $uri->getHost() === '') {
                    continue;
                }
                $prefixes[] = $prefix;
                $origin = strtolower($uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()));

                if ($grant->credential !== null) {
                    $credentials[] = new CredentialGrant(
                        $grant->credential->name,
                        $origin,
                        $grant->credential->parameter,
                        $grant->credential->value,
                        $grant->credential->placement,
                    );
                }
            }
        }

        $pluginStage = $stagingDirectory . '/.plugin-' . bin2hex(random_bytes(6));

        try {
            Filesystem\create_directory($pluginStage, 0700);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Plugin Broadcast staging could not be created.', 0, $exception);
        }
        $this->copyOutputs($stagingDirectory, $pluginStage);
        $invocation = new Invocation(
            $package,
            $pluginStage,
            array_values(array_unique($prefixes)),
            $credentials,
            helpers: $this->helperGrants($package, $helper),
            transport: new PluginBroadcastHttpTransport($fixtureDirectory),
        );
        $resources = [];
        $process = null;

        try {
            $process = $this->runner->start($this->pluginId, $pluginStage);
            /** @var array<string, mixed> $pluginParams */
            $pluginParams = $this->pluginParams($params);
            $capabilityHandler = /** @param array<string, mixed> $message */ function (array $message) use ($invocation, &$resources, $onProgress): array {
                $method = is_string($message['method'] ?? null) ? $message['method'] : '';
                $params = $this->stringKeyed($message['params'] ?? null);

                return match ($method) {
                    'http.request' => $this->http($invocation, $params, $resources),
                    'resource.read' => $this->readResource($resources, $params),
                    'staging.write' => $this->writeStaging($invocation, $params),
                    'staging.stage' => $this->stageStaging($invocation, $params),
                    'helper.run' => $this->runHelper($invocation, $params),
                    'event.log' => ['accepted' => true],
                    'event.progress' => $this->progress($params, $onProgress),
                    default => throw new RuntimeException('Plugin capability is not supported: ' . $method),
                };
            };
            $result = $process->invoke($method, $pluginParams, $capabilityHandler);

            if (isset($result['error'])) {
                $error = is_array($result['error']) ? $result['error'] : [];
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'Plugin failed.';
                $stderr = $process->stderr();

                if (trim($stderr) !== '') {
                    $message .= ' (' . trim($stderr) . ')';
                }

                throw new RuntimeException($message);
            }

            $this->copyOutputs($pluginStage, $stagingDirectory);

            return $result;
        } finally {
            foreach ($resources as $resource) {
                $resource->close();
            }

            if ($process !== null) {
                $process->close();
            }
            $invocation->close();
        }
    }

    /** @param array<string, mixed> $params
     * @param  array<string, ReadableResource>  $resources
     * @return array<string, mixed>
     */
    private function http(Invocation $invocation, array $params, array &$resources): array
    {
        $response = $invocation->http(
            is_string($params['method'] ?? null) ? $params['method'] : 'GET',
            is_string($params['url'] ?? null) ? $params['url'] : '',
            $this->stringHeaders($params['headers'] ?? null),
            is_string($params['body'] ?? null) ? $params['body'] : null,
            is_string($params['credential'] ?? null) ? $params['credential'] : null,
        );

        if ($response->resource === null) {
            return ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body()];
        }
        $reference = 'resource-' . count($resources) . '-' . bin2hex(random_bytes(4));
        $resources[$reference] = $response->resource;

        return ['status' => $response->status, 'headers' => $response->headers, 'resource' => $reference];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{accepted: true}
     */
    private function progress(array $params, ?callable $onProgress): array
    {
        if ($onProgress !== null && is_string($params['stage'] ?? null)) {
            $fraction = is_float($params['fraction'] ?? null) || is_int($params['fraction'] ?? null)
                ? (float) $params['fraction']
                : null;
            $onProgress($params['stage'], $fraction);
        }

        return ['accepted' => true];
    }

    /** @param array<string, ReadableResource> $resources
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function readResource(array $resources, array $params): array
    {
        $reference = is_string($params['reference'] ?? null) ? $params['reference'] : '';
        $resource = $resources[$reference] ?? throw new RuntimeException('Plugin HTTP resource is not granted.');

        $maximumBytes = is_int($params['maximum_bytes'] ?? null) ? $params['maximum_bytes'] : 65536;

        return ['data' => base64_encode($resource->read(max(1, $maximumBytes))), 'eof' => $resource->isEof()];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function writeStaging(Invocation $invocation, array $params): array
    {
        $content = is_string($params['content'] ?? null) ? base64_decode($params['content'], true) : false;

        if ($content === false) {
            throw new RuntimeException('Plugin staging content is invalid.');
        }
        $relativePath = is_string($params['relative_path'] ?? null) ? $params['relative_path'] : '';
        $artifact = $invocation->staging()->write($relativePath, $content, is_string($params['media_type'] ?? null) ? $params['media_type'] : null);

        return ['reference' => $artifact->reference, 'media_type' => $artifact->mediaType, 'size_bytes' => $artifact->sizeBytes];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function stageStaging(Invocation $invocation, array $params): array
    {
        $relativePath = is_string($params['relative_path'] ?? null) ? $params['relative_path'] : '';
        $artifact = $invocation->staging()->stage($relativePath, is_string($params['media_type'] ?? null) ? $params['media_type'] : null);

        return ['reference' => $artifact->reference, 'media_type' => $artifact->mediaType, 'size_bytes' => $artifact->sizeBytes];
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function runHelper(Invocation $invocation, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? array_values($params['arguments']) : [];
        $result = $invocation->runHelper($name, $arguments);

        return ['exit_code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
    }

    /** @return list<HelperGrant> */
    private function helperGrants(string $package, ?PluginHelperGrant $helper): array
    {
        if ($helper === null) {
            return [];
        }
        $root = realpath($package);

        if ($root === false || $helper->packageRoot === null) {
            throw new RuntimeException('Plugin helper is outside the active plugin package.');
        }
        $sourceRoot = realpath($helper->packageRoot);

        if ($sourceRoot === false || ! str_starts_with($helper->executable, $sourceRoot . '/')) {
            throw new RuntimeException('Plugin helper is outside the active plugin package.');
        }

        $executable = realpath($root . '/' . substr($helper->executable, strlen($sourceRoot) + 1));

        if ($executable === false || ! str_starts_with($executable, $root . '/')) {
            throw new RuntimeException('Plugin helper is outside the active plugin package.');
        }

        return [new HelperGrant($helper->name, substr($executable, strlen($root) + 1), $helper->network)];
    }

    /** @param array<string, mixed> $publication
     * @return array<string, mixed>
     */
    private function normalizePublication(array $publication): array
    {
        $files = [];
        $artifacts = [];

        foreach (is_array($publication['files'] ?? null) ? $publication['files'] : [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            $files[] = [
                'item_id' => $file['item-id'] ?? null,
                'source_reference' => $file['source-reference'] ?? null,
                'relative_path' => $file['relative-path'] ?? null,
            ];
        }
        $publication['files'] = $files;

        foreach (is_array($publication['artifacts'] ?? null) ? $publication['artifacts'] : [] as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifacts[] = [
                'item_id' => $artifact['item-id'] ?? null,
                'reference' => $artifact['reference'] ?? null,
                'derived_from_reference' => $artifact['derived-from-reference'] ?? null,
                'derivation_key' => $artifact['derivation-key'] ?? null,
                'kind' => $artifact['kind'] ?? null,
            ];
        }
        $publication['artifacts'] = $artifacts;

        return $publication;
    }

    /** @return array<string, mixed> */
    private function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function pluginParams(array $params): array
    {
        if (is_array($params['request'] ?? null)) {
            /** @var array<string, mixed> $request */
            $request = $params['request'];
            $params['request'] = $this->pluginParams($request);
        }

        foreach (['settings', 'payload'] as $key) {
            if (is_array($params[$key] ?? null)) {
                $params[$key] = $this->pluginSettings($params[$key]);
            }
        }

        if (is_array($params['sources'] ?? null)) {
            foreach ($params['sources'] as $index => $source) {
                if (! is_array($source)) {
                    continue;
                }

                if (is_array($source['settings'] ?? null)) {
                    $source['settings'] = $this->pluginSettings($source['settings']);
                    $params['sources'][$index] = $source;
                }
            }
        }

        return $params;
    }

    /** @return list<array<string, mixed>> */
    private function pluginSettings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $settings = [];

        foreach ($value as $setting) {
            if (! is_array($setting)) {
                continue;
            }

            /** @var array<string, mixed> $setting */
            if (isset($setting['value']) && is_array($setting['value']) && isset($setting['value']['kind'])) {
                $setting['value']['tag'] = $setting['value']['kind'];
                unset($setting['value']['kind']);
            }
            $settings[] = $setting;
        }

        return $settings;
    }

    /** @return array<string, string> */
    private function stringHeaders(mixed $value): array
    {
        $result = [];

        foreach ($this->stringKeyed($value) as $key => $item) {
            if (is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function copyOutputs(string $source, string $destination): void
    {
        foreach (Filesystem\list_directory($source) as $from) {
            $entry = basename($from);

            if (str_starts_with($entry, '.')) {
                continue;
            }
            $to = $destination . '/' . $entry;

            if (Filesystem\is_directory($from)) {
                if (! Filesystem\is_directory($to)) {
                    Filesystem\create_directory($to, 0700);
                }
                $this->copyOutputs($from, $to);

                continue;
            }
            Filesystem\copy_file($from, $to, overwrite: true);
        }
    }
}

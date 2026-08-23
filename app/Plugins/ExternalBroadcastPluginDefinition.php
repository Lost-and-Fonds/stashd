<?php

declare(strict_types=1);

namespace App\Plugins;

use RuntimeException;
use Tempest\Support\Filesystem;

/** Data-only registration for an external Broadcast Component. */
final readonly class ExternalBroadcastPluginDefinition
{
    /** @param array<int, array<string, mixed>> $uiOptions
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, string>  $supportedFileKinds
     * @param  array<string, string>  $helpers
     * @param  array<string, string>  $operations
     * @param  array<int, array<string, mixed>>  $sourceOptions
     */
    public function __construct(
        public string $id,
        public string $logicalKey,
        public string $runtime,
        public string $name,
        public string $version,
        public string $componentPath,
        public string $socketPath,
        public array $uiOptions,
        public array $actions,
        public array $supportedFileKinds,
        public ?string $outputPath,
        public string $outputMediaType,
        public bool $supportsItemRebuild,
        public bool $prunesAfterPublish,
        public array $helpers,
        public array $operations,
        public string $packageRoot,
        public ?string $prepareHelper,
        public ?string $connectionSettingKey,
        public ?string $credentialName,
        public ?string $credentialParameter,
        public string $credentialPlacement,
        public array $sourceOptions,
    ) {}

    public static function fromManifest(mixed $raw, string $root, string $socketPath, ?string $manifestDirectory = null): ?self
    {
        if (! is_array($raw) || ! is_string($raw['broadcast_key'] ?? null)) {
            return null;
        }

        $required = static function (string $key) use ($raw): string {
            $value = $raw[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("External Broadcast plugin manifest requires {$key}.");
            }

            return trim($value);
        };

        $runtime = is_string($raw['application_runtime'] ?? null) && trim($raw['application_runtime']) !== '' ? trim($raw['application_runtime']) : 'plugin';

        if ($runtime !== 'plugin') {
            throw new RuntimeException("Unsupported external Broadcast runtime [{$runtime}].");
        }

        $component = null;

        if (is_string($raw['component_environment'] ?? null)) {
            $value = getenv($raw['component_environment']);
            $component = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $component ??= is_string($raw['component'] ?? null) ? trim($raw['component']) : '';

        /** @var array<int, array<string, mixed>> $uiOptions */
        $uiOptions = is_array($raw['ui_options'] ?? null) ? array_values(array_filter($raw['ui_options'], 'is_array')) : [];
        /** @var array<int, array<string, mixed>> $actions */
        $actions = is_array($raw['actions'] ?? null) ? array_values(array_filter($raw['actions'], 'is_array')) : [];
        /** @var array<int, string> $supportedFileKinds */
        $supportedFileKinds = is_array($raw['supported_file_kinds'] ?? null)
            ? array_values(array_filter($raw['supported_file_kinds'], 'is_string'))
            : ['audio', 'video'];

        $packageRoot = $manifestDirectory !== null ? rtrim($manifestDirectory, '/') : rtrim($root, '/') . '/plugins/' . $required('id');
        $runtimePackageRoot = $packageRoot;
        $componentPath = str_starts_with($component, '/') ? $component : rtrim($root, '/') . '/' . ltrim($component, '/');
        $helpers = [];

        if (is_array($raw['helpers'] ?? null)) {
            foreach ($raw['helpers'] as $name => $helper) {
                if (! is_string($name) || ! is_array($helper) || ! is_string($helper['executable'] ?? null)) {
                    continue;
                }

                $relative = trim($helper['executable']);

                if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                    throw new RuntimeException('External Broadcast plugin helper paths must be package-relative.');
                }

                $candidate = $packageRoot . '/' . ltrim($relative, '/');

                if (! Filesystem\is_file($candidate)) {
                    $componentPackageRoot = dirname($componentPath);
                    $packageName = basename($packageRoot);

                    while ($componentPackageRoot !== dirname($componentPackageRoot)) {
                        if (basename($componentPackageRoot) === $packageName) {
                            $runtimePackageRoot = $componentPackageRoot;

                            break;
                        }
                        $componentPackageRoot = dirname($componentPackageRoot);
                    }

                    if ($runtimePackageRoot === $packageRoot) {
                        $runtimePackageRoot = rtrim(dirname($componentPath), '/') . '/' . $packageName;
                    }
                    $candidate = $runtimePackageRoot . '/' . ltrim($relative, '/');
                }
                $helpers[$name] = $candidate;
            }
        }
        /** @var array<string, string> $operations */
        $operations = [];

        if (is_array($raw['operations'] ?? null)) {
            foreach ($raw['operations'] as $name => $operation) {
                if (is_string($name) && is_string($operation) && trim($operation) !== '') {
                    $operations[$name] = trim($operation);
                }
            }
        }

        /** @var array<int, array<string, mixed>> $sourceOptions */
        $sourceOptions = is_array($raw['source_options'] ?? null)
            ? array_values(array_filter($raw['source_options'], 'is_array'))
            : [];

        return new self(
            id: $required('id'),
            logicalKey: trim($raw['broadcast_key']),
            runtime: $runtime,
            name: is_string($raw['name'] ?? null) && trim($raw['name']) !== '' ? trim($raw['name']) : $required('id'),
            version: is_string($raw['version'] ?? null) && trim($raw['version']) !== '' ? trim($raw['version']) : '0.0.0',
            componentPath: '',
            socketPath: $socketPath,
            uiOptions: $uiOptions,
            actions: $actions,
            supportedFileKinds: $supportedFileKinds,
            outputPath: is_string($raw['output_path'] ?? null) && trim($raw['output_path']) !== '' ? trim($raw['output_path']) : null,
            outputMediaType: is_string($raw['output_media_type'] ?? null) && trim($raw['output_media_type']) !== '' ? trim($raw['output_media_type']) : 'application/octet-stream',
            supportsItemRebuild: ($raw['supports_item_rebuild'] ?? false) === true,
            prunesAfterPublish: ($raw['prunes_after_publish'] ?? false) === true,
            helpers: $helpers,
            operations: $operations,
            packageRoot: $runtimePackageRoot,
            prepareHelper: is_string($raw['prepare_helper'] ?? null) && trim($raw['prepare_helper']) !== '' ? trim($raw['prepare_helper']) : null,
            connectionSettingKey: is_string($raw['connection_setting_key'] ?? null) && trim($raw['connection_setting_key']) !== '' ? trim($raw['connection_setting_key']) : null,
            credentialName: is_array($raw['credential'] ?? null) && is_string($raw['credential']['name'] ?? null) ? trim($raw['credential']['name']) : null,
            credentialParameter: is_array($raw['credential'] ?? null) && is_string($raw['credential']['parameter'] ?? null) ? trim($raw['credential']['parameter']) : null,
            credentialPlacement: is_array($raw['credential'] ?? null) && in_array($raw['credential']['placement'] ?? null, ['query', 'header'], true) ? $raw['credential']['placement'] : 'query',
            sourceOptions: $sourceOptions,
        );
    }

    public function available(): bool
    {
        return $this->runtime === 'plugin';
    }

    public function helperGrant(string $name): ?PluginHelperGrant
    {
        $executable = $this->helpers[$name] ?? null;

        return $executable !== null && Filesystem\is_file($executable)
            ? new PluginHelperGrant($name, $executable, $this->packageRoot)
            : null;
    }
}

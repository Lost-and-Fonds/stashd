<?php

declare(strict_types=1);

namespace App\Plugins;

use RuntimeException;

/** Data-only registration for an external Broadcast Component. */
final readonly class ExternalBroadcastPluginDefinition
{
    /** @param array<int, array<string, mixed>> $uiOptions
     *  @param array<int, array<string, mixed>> $actions
     *  @param array<int, string> $supportedFileKinds
     */
    public function __construct(
        public string $id,
        public string $logicalKey,
        public string $name,
        public string $version,
        public string $componentPath,
        public string $socketPath,
        public array $uiOptions,
        public array $actions,
        public array $supportedFileKinds,
        public string $outputPath,
        public string $outputMediaType,
        public bool $supportsItemRebuild,
        public bool $prunesAfterPublish,
    ) {
    }

    public static function fromManifest(mixed $raw, string $root, string $socketPath): ?self
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

        $component = null;
        if (is_string($raw['component_environment'] ?? null)) {
            $value = getenv($raw['component_environment']);
            $component = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $component ??= is_string($raw['component'] ?? null) ? trim($raw['component']) : '';
        if ($component === '') {
            throw new RuntimeException('External Broadcast plugin manifest requires component.');
        }

        /** @var array<int, array<string, mixed>> $uiOptions */
        $uiOptions = is_array($raw['ui_options'] ?? null) ? array_values(array_filter($raw['ui_options'], 'is_array')) : [];
        /** @var array<int, array<string, mixed>> $actions */
        $actions = is_array($raw['actions'] ?? null) ? array_values(array_filter($raw['actions'], 'is_array')) : [];
        /** @var array<int, string> $supportedFileKinds */
        $supportedFileKinds = is_array($raw['supported_file_kinds'] ?? null)
            ? array_values(array_filter($raw['supported_file_kinds'], 'is_string'))
            : ['audio', 'video'];

        return new self(
            id: $required('id'),
            logicalKey: trim($raw['broadcast_key']),
            name: is_string($raw['name'] ?? null) && trim($raw['name']) !== '' ? trim($raw['name']) : $required('id'),
            version: is_string($raw['version'] ?? null) && trim($raw['version']) !== '' ? trim($raw['version']) : '0.0.0',
            componentPath: str_starts_with($component, '/') ? $component : rtrim($root, '/') . '/' . ltrim($component, '/'),
            socketPath: $socketPath,
            uiOptions: $uiOptions,
            actions: $actions,
            supportedFileKinds: $supportedFileKinds,
            outputPath: is_string($raw['output_path'] ?? null) && trim($raw['output_path']) !== '' ? trim($raw['output_path']) : 'output.bin',
            outputMediaType: is_string($raw['output_media_type'] ?? null) && trim($raw['output_media_type']) !== '' ? trim($raw['output_media_type']) : 'application/octet-stream',
            supportsItemRebuild: ($raw['supports_item_rebuild'] ?? false) === true,
            prunesAfterPublish: ($raw['prunes_after_publish'] ?? false) === true,
        );
    }

    public function available(): bool
    {
        return is_file($this->componentPath) && file_exists($this->socketPath);
    }
}

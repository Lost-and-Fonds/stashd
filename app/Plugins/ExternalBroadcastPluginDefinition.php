<?php

declare(strict_types=1);

namespace App\Plugins;

use RuntimeException;

/** Data-only registration for an external Broadcast Component. */
final readonly class ExternalBroadcastPluginDefinition
{
    public function __construct(
        public string $id,
        public string $logicalKey,
        public string $name,
        public string $version,
        public string $componentPath,
        public string $socketPath,
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

        return new self(
            id: $required('id'),
            logicalKey: trim($raw['broadcast_key']),
            name: is_string($raw['name'] ?? null) && trim($raw['name']) !== '' ? trim($raw['name']) : $required('id'),
            version: is_string($raw['version'] ?? null) && trim($raw['version']) !== '' ? trim($raw['version']) : '0.0.0',
            componentPath: str_starts_with($component, '/') ? $component : rtrim($root, '/') . '/' . ltrim($component, '/'),
            socketPath: $socketPath,
        );
    }

    public function available(): bool
    {
        return is_file($this->componentPath) && file_exists($this->socketPath);
    }
}

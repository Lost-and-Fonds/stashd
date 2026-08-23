<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class ExternalBroadcastPluginRegistry
{
    /**
     * @param  list<ExternalBroadcastPluginDefinition>  $plugins
     * @param  array<string, array<string, BroadcastPluginRuntime>>  $runtimes
     */
    public function __construct(private array $plugins, private array $runtimes = []) {}

    public function findByLogicalKey(string $key): ?ExternalBroadcastPluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->logicalKey === $key) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<ExternalBroadcastPluginDefinition> */
    public function all(): array
    {
        return $this->plugins;
    }

    /** @return array<string, BroadcastPluginRuntime> */
    public function runtimesFor(string $key): array
    {
        return $this->runtimes[$key] ?? [];
    }

    public function runtimeFor(string $key): ?BroadcastPluginRuntime
    {
        $selected = 'wasmtime';
        $configured = getenv('STASHD_BROADCAST_IMPLEMENTATIONS');
        if (is_string($configured) && trim($configured) !== '') {
            $map = json_decode($configured, true);
            if (is_array($map) && is_string($map[$key] ?? null)) {
                $selected = $map[$key];
            }
        }

        return $this->runtimes[$key][$selected] ?? null;
    }
}

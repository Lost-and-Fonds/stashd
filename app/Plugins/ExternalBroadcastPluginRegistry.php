<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Stashes\StashCollectionExporter;

final readonly class ExternalBroadcastPluginRegistry
{
    /**
     * @param  list<ExternalBroadcastPluginDefinition>  $plugins
     * @param  array<string, array<string, BroadcastPluginRuntime>>  $runtimes
     */
    /** @param list<StashCollectionExporter> $collectionExporters */
    public function __construct(private array $plugins, private array $runtimes = [], private array $collectionExporters = []) {}

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
        return $this->runtimes[$key]['plugin'] ?? null;
    }

    /** @return list<StashCollectionExporter> */
    public function collectionExporters(): array
    {
        return $this->collectionExporters;
    }
}

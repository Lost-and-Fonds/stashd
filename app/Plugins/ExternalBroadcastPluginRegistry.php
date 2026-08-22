<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class ExternalBroadcastPluginRegistry
{
    /** @param list<ExternalBroadcastPluginDefinition> $plugins */
    public function __construct(private array $plugins) {}

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
}

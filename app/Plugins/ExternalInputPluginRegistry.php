<?php

declare(strict_types=1);

namespace App\Plugins;

use InvalidArgumentException;
use Tempest\Container\Singleton;

#[Singleton]
final class ExternalInputPluginRegistry
{
    /** @param list<ExternalInputPlugin> $plugins */
    public function __construct(private array $plugins) {}

    public function get(string $id): ExternalInputPlugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->key() === $id) {
                return $plugin;
            }
        }

        throw new InvalidArgumentException("Unknown external Input plugin: {$id}");
    }

    public function find(string $id): ?ExternalInputPlugin
    {
        foreach ($this->providers() as $plugin) {
            if ($plugin->key() === $id) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<ExternalInputPlugin> */
    public function providers(): array
    {
        return array_values(array_filter(
            $this->plugins,
            static fn(ExternalInputPlugin $plugin): bool => $plugin->isRuntimeAvailable(),
        ));
    }
}

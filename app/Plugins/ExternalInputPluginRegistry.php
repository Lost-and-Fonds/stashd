<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Providers\Provider;
use InvalidArgumentException;
use Tempest\Container\Singleton;

#[Singleton]
final class ExternalInputPluginRegistry
{
    /** @param list<ExternalInputPlugin> $plugins */
    public function __construct(private array $plugins)
    {
    }

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
        foreach ($this->plugins as $plugin) {
            if ($plugin->key() === $id) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<Provider> */
    public function providers(): array
    {
        return $this->plugins;
    }
}

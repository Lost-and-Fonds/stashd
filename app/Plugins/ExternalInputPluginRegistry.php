<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Downloads\DownloaderInterface;
use App\Providers\Provider;
use App\Providers\ResolvedInput;
use Tempest\Container\Singleton;

#[Singleton]
final class ExternalInputPluginRegistry
{
    /** @param list<Provider> $plugins
     * @param list<PluginInputDefinition> $definitions
     */
    public function __construct(private array $plugins = [], private array $definitions = []) {}

    public function get(string $id): Provider
    {
        return $this->find($id) ?? throw new \InvalidArgumentException("Unknown external Input plugin: {$id}");
    }

    public function find(string $id): ?Provider
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->key() === $id) {
                return $plugin;
            }
        }

        return null;
    }

    public function findDownloader(string $id): ?DownloaderInterface
    {
        $plugin = $this->find($id);

        return $plugin instanceof DownloaderInterface ? $plugin : null;
    }

    public function definition(string $id): ?PluginInputDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<PluginInputDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @param array<string, bool|int|string> $source */
    public function resolveSource(string $id, array $source): ResolvedInput
    {
        $definition = $this->definition($id)
            ?? throw new \InvalidArgumentException("Unknown external Input plugin: {$id}");
        $plugin = $this->find($definition->providerKey);

        if (! $plugin instanceof SourceResolvingInputProvider) {
            throw new \InvalidArgumentException("Input plugin {$id} cannot resolve sources.");
        }

        return $plugin->resolveSource($source);
    }

    /** @return list<Provider> */
    public function providers(): array
    {
        return $this->plugins;
    }
}

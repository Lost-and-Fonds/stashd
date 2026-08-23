<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Downloads\DownloaderInterface;
use App\Providers\Provider;
use Tempest\Container\Singleton;

#[Singleton]
final class ExternalInputPluginRegistry
{
    /** @param list<Provider> $plugins */
    public function __construct(private array $plugins = []) {}

    public function get(string $id): Provider
    {
        return $this->find($id) ?? throw new \InvalidArgumentException("Unknown external Input plugin: {$id}");
    }

    public function find(string $id): ?Provider
    {
        foreach ($this->plugins as $plugin) if ($plugin->key() === $id) return $plugin;
        return null;
    }

    public function findDownloader(string $id): ?DownloaderInterface
    {
        $plugin = $this->find($id);
        return $plugin instanceof DownloaderInterface ? $plugin : null;
    }

    /** @return list<Provider> */
    public function providers(): array
    {
        return $this->plugins;
    }
}

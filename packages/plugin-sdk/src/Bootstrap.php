<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

interface PluginEntrypoint
{
    public function register(PluginRegistry $registry): void;
}

final class PluginRegistry
{
    /** @var array<string, BroadcastPlugin> */
    private array $broadcasts = [];
    /** @var array<string, InputPlugin> */
    private array $inputs = [];

    public function broadcast(string $id, BroadcastPlugin $plugin): void
    {
        $this->broadcasts[$id] = $plugin;
    }
    public function input(string $id, InputPlugin $plugin): void
    {
        $this->inputs[$id] = $plugin;
    }
    public function broadcastPlugin(string $id): BroadcastPlugin
    {
        return $this->broadcasts[$id] ?? throw new RuntimeException("Unknown broadcast plugin: $id");
    }
    public function inputPlugin(string $id): InputPlugin
    {
        return $this->inputs[$id] ?? throw new RuntimeException("Unknown input plugin: $id");
    }
}

final class PluginBootstrap
{
    public static function load(PluginEntrypoint $entrypoint): PluginRegistry
    {
        $registry = new PluginRegistry();
        $entrypoint->register($registry);
        return $registry;
    }
}

final class PluginInvoker
{
    public static function publish(callable $handler, PublishRequest $request): Publication
    {
        $result = $handler($request);
        if (!$result instanceof Publication) {
            throw new InvalidPluginResultException('publish returned an invalid result');
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

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

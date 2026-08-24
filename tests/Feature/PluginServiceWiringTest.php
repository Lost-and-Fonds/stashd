<?php

declare(strict_types=1);
use App\Broadcasts\BroadcastLifecycleService;
use App\Commands\CommandHandlerRegistry;
use App\Commands\CommandType;
use App\Connections\PluginConnectionService;

test('container resolves broadcast and generic connection services', function (): void {
    expect($this->container->get(BroadcastLifecycleService::class))->not->toBeNull()
        ->and($this->container->get(PluginConnectionService::class))->not->toBeNull()
        ->and($this->container->get(CommandHandlerRegistry::class)->handlerFor(CommandType::BroadcastRebuild))->not->toBeNull()
        ->and($this->container->get(CommandHandlerRegistry::class)->handlerFor(CommandType::BroadcastRebuildItem))->not->toBeNull();
});

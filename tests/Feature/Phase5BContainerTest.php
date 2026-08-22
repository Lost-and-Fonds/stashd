<?php

declare(strict_types=1);

test('container resolves broadcast and generic connection services', function (): void {
    expect($this->container->get(\App\Broadcasts\BroadcastLifecycleService::class))->not->toBeNull()
        ->and($this->container->get(\App\Connections\PluginConnectionService::class))->not->toBeNull()
        ->and($this->container->get(\App\Commands\CommandHandlerRegistry::class)->handlerFor(\App\Commands\CommandType::BroadcastRebuild))->not->toBeNull()
        ->and($this->container->get(\App\Commands\CommandHandlerRegistry::class)->handlerFor(\App\Commands\CommandType::BroadcastRebuildItem))->not->toBeNull();
});

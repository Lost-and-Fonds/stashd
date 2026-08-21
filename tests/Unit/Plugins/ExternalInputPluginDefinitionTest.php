<?php

declare(strict_types=1);

use App\Plugins\ExternalInputPluginDefinition;

test('external operation availability is declared by the plugin manifest', function (): void {
    $definition = ExternalInputPluginDefinition::fromManifest([
        'id' => 'pure-wasm',
        'component' => 'plugin.wasm',
        'operations' => [
            'resolve' => [],
            'refresh' => [],
            'complete' => [],
            'acquire' => [],
        ],
    ], '/tmp/plugins', '/tmp/plugin-host.sock');

    expect($definition->supportsOperation('complete'))->toBeTrue()
        ->and($definition->operationRequiresCredential('complete'))->toBeFalse()
        ->and($definition->operationRequiresHelper('acquire'))->toBeFalse();
});

test('manifest capabilities are the only declared runtime requirements', function (): void {
    $definition = ExternalInputPluginDefinition::fromManifest([
        'id' => 'capability-backed',
        'component' => 'plugin.wasm',
        'operations' => [
            'complete' => ['credential:provider-api'],
            'acquire' => ['helper:media-tool'],
        ],
        'helper' => [
            'name' => 'media-tool',
            'executable' => '/usr/bin/media-tool',
        ],
    ], '/tmp/plugins', '/tmp/plugin-host.sock');

    expect($definition->operationRequiresCredential('complete'))->toBeTrue()
        ->and($definition->operationRequiresHelper('acquire'))->toBeTrue();
});

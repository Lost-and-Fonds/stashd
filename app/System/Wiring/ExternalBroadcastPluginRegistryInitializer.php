<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ComposerPluginPackageDiscovery;
use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\NativeBroadcastRuntime;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Runner\NativePluginRunner;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalBroadcastPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalBroadcastPluginRegistry
    {
        $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : '/tmp/stashd-plugin-host.sock';
        $definitions = [];
        $packages = new PackageManager(dirname(__DIR__, 3) . '/.stashd/native-plugins');
        foreach ((new ComposerPluginPackageDiscovery())->all() as $package) {
            $definition = ExternalBroadcastPluginDefinition::fromManifest($package['manifest'], $package['root'], $socket, dirname($package['manifest_path']));
            if ($definition === null) {
                continue;
            }
            $packages->link($definition->id, $package['root']);
            $definitions[$definition->logicalKey][] = $definition;
        }

        $runner = new NativePluginRunner($packages);
        $plugins = [];
        $runtimes = [];

        foreach ($definitions as $logicalKey => $candidates) {
            $base = $candidates[0];
            $available = [];
            foreach ($candidates as $candidate) {
                if ($candidate->runtime === 'native' && $packages->activePath($candidate->id) !== null) {
                    $available['native'] = new NativeBroadcastRuntime($runner, $packages, $candidate->id);
                }
            }
            if ($available !== []) {
                $plugins[] = $base;
                $runtimes[$logicalKey] = $available;
            }
        }

        return new ExternalBroadcastPluginRegistry($plugins, $runtimes);
    }
}

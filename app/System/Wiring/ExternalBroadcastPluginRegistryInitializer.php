<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ComposerPluginPackageDiscovery;
use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\PluginBroadcastRuntime;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalBroadcastPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalBroadcastPluginRegistry
    {
        $socket = '';
        $definitions = [];
        $root = getenv('STASHD_PLUGIN_PACKAGE_ROOT');
        $packages = new PackageManager(is_string($root) && trim($root) !== '' ? trim($root) : dirname(__DIR__, 3) . '/.stashd/plugin-packages');

        foreach ((new ComposerPluginPackageDiscovery())->all($packages->activeRoot()) as $package) {
            $definition = ExternalBroadcastPluginDefinition::fromManifest($package['manifest'], $package['root'], $socket, dirname($package['manifest_path']));

            if ($definition === null) {
                continue;
            }

            if ($packages->activePath($definition->id) !== (realpath($package['root']) ?: $package['root'])) {
                $packages->link($definition->id, $package['root']);
            }
            $definitions[$definition->logicalKey][] = $definition;
        }

        $runner = new PluginRunner($packages);
        $plugins = [];
        $runtimes = [];

        foreach ($definitions as $logicalKey => $candidates) {
            $base = $candidates[0];
            $available = [];

            foreach ($candidates as $candidate) {
                if ($candidate->runtime === 'plugin' && $packages->activePath($candidate->id) !== null) {
                    $available['plugin'] = new PluginBroadcastRuntime($runner, $packages, $candidate->id);
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

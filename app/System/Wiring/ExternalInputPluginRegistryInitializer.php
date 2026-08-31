<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ComposerPluginPackageDiscovery;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\Plugins\PluginInputRuntime;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;
use Tempest\Cache\Cache;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalInputPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalInputPluginRegistry
    {
        $root = getenv('STASHD_PLUGIN_PACKAGE_ROOT');
        $packages = new PackageManager(is_string($root) && trim($root) !== '' ? trim($root) : dirname(__DIR__, 3) . '/.stashd/plugin-packages');
        $definitions = [];

        foreach ((new ComposerPluginPackageDiscovery())->all($packages->activeRoot()) as $package) {
            $definition = PluginInputDefinition::from($package['manifest'], $package['root']);

            if ($definition === null) {
                continue;
            }

            if ($packages->activePath($definition->id) !== (realpath($package['root']) ?: $package['root'])) {
                $packages->link($definition->id, $package['root']);
            }
            $definitions[] = $definition;
        }
        $runner = new PluginRunner($packages);
        $providers = array_map(static fn(PluginInputDefinition $definition): PluginInputRuntime => new PluginInputRuntime($definition, $runner, $packages, $container->get(\App\System\Secret\SecretsService::class), $container->get(Cache::class)), $definitions);

        return new ExternalInputPluginRegistry($providers, $definitions);
    }
}

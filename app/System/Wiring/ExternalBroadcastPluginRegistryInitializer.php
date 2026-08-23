<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\NativeBroadcastRuntime;
use App\Plugins\PluginHostClient;
use App\Plugins\WasmtimeBroadcastRuntime;
use RuntimeException;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Runner\NativePluginRunner;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalBroadcastPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalBroadcastPluginRegistry
    {
        $root = dirname(__DIR__, 3);
        $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : '/tmp/stashd-plugin-host.sock';
        $definitions = [];

        foreach (glob($root . '/plugins/*/plugin.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new RuntimeException("Invalid external plugin manifest: {$manifestPath}");
            }

            $definition = ExternalBroadcastPluginDefinition::fromManifest($manifest, $root, $socket, dirname($manifestPath));
            if ($definition !== null) {
                $definitions[$definition->logicalKey][] = $definition;
            }
        }

        $nativeRoot = getenv('STASHD_NATIVE_PLUGIN_ROOT');
        $nativeRoot = is_string($nativeRoot) && trim($nativeRoot) !== '' ? trim($nativeRoot) : $root . '/.stashd/native-plugins';
        $packages = new PackageManager($nativeRoot);
        $runner = new NativePluginRunner($packages);
        $plugins = [];
        $runtimes = [];

        foreach ($definitions as $logicalKey => $candidates) {
            $base = $candidates[0];
            foreach ($candidates as $candidate) {
                if ($candidate->runtime === 'wasmtime' && $candidate->available()) {
                    $base = $candidate;

                    break;
                }
            }
            $available = [];
            foreach ($candidates as $candidate) {
                if ($candidate->runtime === 'wasmtime' && $candidate->available()) {
                    $available['wasmtime'] = new WasmtimeBroadcastRuntime(new PluginHostClient($candidate->socketPath), $candidate->componentPath);
                }
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

<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Plugins\ExternalBroadcastPluginRegistry;
use RuntimeException;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalBroadcastPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalBroadcastPluginRegistry
    {
        $root = dirname(__DIR__, 3);
        $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : '/tmp/stashd-plugin-host.sock';
        $plugins = [];

        foreach (glob($root . '/plugins/*/plugin.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new RuntimeException("Invalid external plugin manifest: {$manifestPath}");
            }

            $definition = ExternalBroadcastPluginDefinition::fromManifest($manifest, $root, $socket, dirname($manifestPath));
            if ($definition !== null) {
                $plugins[] = $definition;
            }
        }

        return new ExternalBroadcastPluginRegistry($plugins);
    }
}

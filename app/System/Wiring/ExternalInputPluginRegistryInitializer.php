<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ExternalInputPlugin;
use App\Plugins\ExternalInputPluginDefinition;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginHostClient;
use App\System\Secret\SecretsService;
use RuntimeException;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalInputPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalInputPluginRegistry
    {
        $root = dirname(__DIR__, 3);
        $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : '/tmp/stashd-plugin-host.sock';
        $plugins = [];
        $secrets = $container->get(SecretsService::class);

        foreach (glob($root . '/plugins/*/plugin.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new RuntimeException("Invalid external Input plugin manifest: {$manifestPath}");
            }

            $definition = ExternalInputPluginDefinition::fromManifest($manifest, $root, $socket);
            $plugins[] = new ExternalInputPlugin(
                $definition,
                new PluginHostClient($definition->socketPath),
                $secrets,
            );
        }

        return new ExternalInputPluginRegistry($plugins);
    }
}

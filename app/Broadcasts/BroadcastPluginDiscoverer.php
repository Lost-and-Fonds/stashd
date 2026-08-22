<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Plugins\ExternalBroadcastPlugin;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Vault\AssetRepository;
use App\Vault\MoveFileIntoVault;
use App\Vault\VaultPathBuilder;
use Psr\Container\ContainerInterface;
use Tempest\Discovery\Discovery;
use Tempest\Discovery\DiscoveryItems;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Discovery\IsDiscovery;
use Tempest\Reflection\ClassReflector;

/**
 * Discovers broadcast plugins by scanning for #[StashdBroadcast] attributes.
 * Populates BroadcastPluginRegistry with resolved plugin instances at boot.
 */
final class BroadcastPluginDiscoverer implements Discovery
{
    use IsDiscovery;

    public function __construct(
        private ContainerInterface $container,
    ) {
        $this->discoveryItems = new DiscoveryItems();
    }

    public function discover(DiscoveryLocation $location, ClassReflector $class): void
    {
        $attribute = $class->getAttribute(StashdBroadcast::class);

        if ($attribute === null) {
            return;
        }

        $this->discoveryItems->add($location, [
            'className' => $class->getName(),
            'name' => $attribute->name,
            'description' => $attribute->description,
        ]);
    }

    public function apply(): void
    {
        $registeredKeys = [];

        /** @var ExternalBroadcastPluginRegistry $externalPlugins */
        $externalPlugins = $this->container->get(ExternalBroadcastPluginRegistry::class);

        foreach ($externalPlugins->all() as $definition) {
            if (! $definition->available()) {
                continue;
            }

            try {
                $host = new \App\Plugins\PluginHostClient($definition->socketPath);
                /** @var BroadcastContextFactory $contexts */
                $contexts = $this->container->get(BroadcastContextFactory::class);
                /** @var BroadcastPathBuilder $paths */
                $paths = $this->container->get(BroadcastPathBuilder::class);
                /** @var BroadcastItemRepository $items */
                $items = $this->container->get(BroadcastItemRepository::class);
                /** @var \App\System\State\StateTransitionService $transitions */
                $transitions = $this->container->get(\App\System\State\StateTransitionService::class);
                /** @var PublishedResourceService $publications */
                $publications = $this->container->get(PublishedResourceService::class);
                /** @var PublishedResourceRepository $publicationRecords */
                $publicationRecords = $this->container->get(PublishedResourceRepository::class);
                /** @var AssetRepository $assets */
                $assets = $this->container->get(AssetRepository::class);
                /** @var MoveFileIntoVault $mover */
                $mover = $this->container->get(MoveFileIntoVault::class);
                /** @var VaultPathBuilder $vaultPaths */
                $vaultPaths = $this->container->get(VaultPathBuilder::class);
                /** @var \App\MediaServers\MediaServerConnectionRepository $connections */
                $connections = $this->container->get(\App\MediaServers\MediaServerConnectionRepository::class);
                /** @var \App\MediaServers\MediaServerConnectionSecrets $connectionSecrets */
                $connectionSecrets = $this->container->get(\App\MediaServers\MediaServerConnectionSecrets::class);
                /** @var \App\Broadcasts\HardlinkPublisher $hardlinks */
                $hardlinks = $this->container->get(\App\Broadcasts\HardlinkPublisher::class);
                $instance = new ExternalBroadcastPlugin(
                    definition: $definition,
                    host: $host,
                    contexts: $contexts,
                    paths: $paths,
                    items: $items,
                    transitions: $transitions,
                    publications: $publications,
                    publicationRecords: $publicationRecords,
                    assets: $assets,
                    mover: $mover,
                    vaultPaths: $vaultPaths,
                    connections: $connections,
                    connectionSecrets: $connectionSecrets,
                    hardlinks: $hardlinks,
                );
            } catch (\Throwable $e) {
                error_log("[stashd] BroadcastPluginDiscoverer: failed to resolve external {$definition->id}: {$e->getMessage()}");

                continue;
            }

            $broadcastKeys = $instance->broadcastKeys();
            $registeredKeys = array_merge($registeredKeys, $broadcastKeys);

            BroadcastPluginRegistry::add(new DiscoveredPlugin(
                className: ExternalBroadcastPlugin::class,
                name: $definition->name,
                description: 'External Broadcast Component',
                broadcastKeys: $broadcastKeys,
                plugin: $instance,
            ));
        }

        foreach ($this->discoveryItems as $meta) {
            try {
                $instance = $this->container->get($meta['className']);
            } catch (\Throwable $e) {
                error_log("[stashd] BroadcastPluginDiscoverer: failed to resolve {$meta['className']}: {$e->getMessage()}");

                continue;
            }

            if (! $instance instanceof BroadcastPlugin) {
                error_log("[stashd] BroadcastPluginDiscoverer: {$meta['className']} has #[StashdBroadcast] but does not implement BroadcastPlugin — skipping.");

                continue;
            }

            $broadcastKeys = $instance->broadcastKeys();

            foreach ($broadcastKeys as $key) {
                if (in_array($key, $registeredKeys, true)) {
                    error_log("[stashd] BroadcastPluginDiscoverer: duplicate broadcast key '{$key}' — skipping {$meta['className']}.");

                    continue 2;
                }
            }

            $registeredKeys = array_merge($registeredKeys, $broadcastKeys);

            BroadcastPluginRegistry::add(new DiscoveredPlugin(
                className: $meta['className'],
                name: $meta['name'],
                description: $meta['description'],
                broadcastKeys: $broadcastKeys,
                plugin: $instance,
            ));
        }
    }
}
